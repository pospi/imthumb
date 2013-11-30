<?php
/**
 * Main image generation class
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-10-18
 */

class ImThumbException extends Exception {}
class ImThumbCriticalException extends ImThumbException {}

class ImThumb
{
	const VERSION = 1.1;

	const ERR_SERVER_CONFIG = 1;	// exception codes
	const ERR_SRC_IMAGE = 2;
	const ERR_OUTPUTTING = 3;
	const ERR_CACHE = 4;
	const ERR_FILTER = 5;
	const ERR_CONFIGURATION = 6;
	const ERR_RATE_EXCEEDED = 7;
	const ERR_HACK_ATTEMPT = 8;

	public static $HAS_MBSTRING;	// used for reliable image length determination. Initialised below class def'n.
	public static $MBSTRING_SHADOW;

	//--------------------------------------------------------------------------
	// Initialisation & configuration

	private $params;

	private $imageHandle = null;
	private $imageType;
	private $mimeType;
	private $imageExt;
	private $src;
	private $mtime;

	private $isValidSrc = true;

	private $hasCache = false;

	private $startTime;	// stats
	private $startCPU;

	public function __construct(Array $params = null)
	{
		if (!class_exists('Imagick')) {
			$this->critical("Could not load ImThumb: ImageMagick is not installed. Please contact your webhost and ask them to install the ImageMagick library", self::ERR_SERVER_CONFIG);
		}

		$this->startTime = microtime(true);

		if (defined('MEMORY_LIMIT') && MEMORY_LIMIT) {
			ini_set('memory_limit', MEMORY_LIMIT);
		}

		ksort($params);	// :NOTE: order the parameters to get consistent cache filenames, as this is factored into filename hashes
		$this->params = $params;

		// store initial CPU stats if we are debugging
		if ($this->param('debug')) {
			$data = getrusage();
			$this->startCPU = array((double)($data['ru_utime.tv_sec'] + $data['ru_utime.tv_usec'] / 1000000), (double)($data['ru_stime.tv_sec'] + $data['ru_stime.tv_usec'] / 1000000));
		}

		// set default width / height if none provided
		if (!$this->param('width') && !$this->param('height')) {
			$this->params['width'] = $this->params['height'] = 100;
		}
	}

	public function __destruct()
	{
		if ($this->imageHandle) {
			$this->imageHandle->destroy();
			unset($this->imageHandle);
		}
	}

	public function param($name, $val = null)
	{
		if (isset($val)) {
			$this->params[$name] = $val;
			return;
		}
		return isset($this->params[$name]) ? $this->params[$name] : null;
	}

	public function params()
	{
		return $this->params;
	}

	// reset all parameters related to externally configurable image output
	public function resetImageParams()
	{
		unset(
			$this->params['src'],
			$this->params['width'],
			$this->params['height'],
			$this->params['quality'],
			$this->params['align'],
			$this->params['cropMode'],
			$this->params['sharpen'],
			$this->params['canvasColor'],
			$this->params['canvasTransparent'],
			$this->params['filters'],
			$this->params['jpgProgressive']
		);
	}

	public function configureUnalteredFitImage()
	{
		$prevW = $this->param('width');
		$prevH = $this->param('height');
		$prevQ = $this->param('quality');

		$this->resetImageParams();

		$this->params['width'] = $prevW;
		$this->params['height'] = $prevH;
		$this->params['quality'] = $prevQ;
		$this->params['cropMode'] = 2;
		$this->params['canvasTransparent'] = 1;
	}

	//--------------------------------------------------------------------------
	// Loading

	public function loadImage($src = null)
	{
		if ($src === null) {
			$src = $this->param('src');
		}

		if ($src) {
			if ($this->param('cache') && ($cachemtime = @filemtime($this->getCachePath()))) {
				$this->loadImageMeta($src);
				// expire caches when source images are updated
				if ($this->mtime > $cachemtime) {
					$this->loadImageFile($src);
					$this->doResize();
				} else {
					$this->hasCache = true;
				}
			} else {
				$this->loadImageFile($src);
				$this->doResize();
			}
		} else {
			$this->critical("No image path specified for thumbnail generation", self::ERR_SRC_IMAGE);
		}
	}

	public function loadImageFile($src)
	{
		$src = $this->getRealImagePath($src);

		$this->loadImageMeta($src);

		if (!$this->isValidSrc) {
			// load up the fallback image if we couldn't find the source
			$this->loadFallbackImage();
		} else {
			$this->imageHandle = new Imagick();
			$this->imageHandle->readImage($src);
		}

		if ($this->mimeType == 'image/jpeg') {
			$this->imageHandle->setFormat('jpg');
		} else if ($this->mimeType == 'image/gif') {
			$this->imageHandle->setFormat('gif');
		} else if ($this->mimeType == 'image/png') {
			$this->imageHandle->setFormat('png');
		}
	}

	protected function loadImageMeta($src)
	{
		$src = $this->getRealImagePath($src);

		$this->mtime = @filemtime($src);
		if (!$this->mtime) {
			$this->isValidSrc = false;
			return;
		}

		$size = @filesize($src);
		if ($size > $this->param('maxSize')) {
			$this->critical("Image file exceeds maximum processable size", self::ERR_SRC_IMAGE);
		}

		$sData = @getimagesize($src);
		if (!$sData) {
			$this->isValidSrc = false;
			return;
		}

		$this->src = $src;
		$this->imageType = $sData[2];

		$this->mimeType = strtolower($sData['mime']);
		if(!preg_match('/^image\//i', $this->mimeType)) {
			$this->mimeType = 'image/' . $this->mimeType;
		}
		if ($this->mimeType == 'image/jpg') {
			$this->mimeType = 'image/jpeg';
		}

		$this->imageExt = substr($src, strrpos($src, '.') + 1);
	}

	public function loadFallbackImage()
	{
		list($w, $h) = $this->getTargetSize();
		return $this->loadFallbackImgOrColor($this->param('fallbackImg'), $this->param('fallbackColor'), $w, $h);
	}

	public function loadErrorImage()
	{
		list($w, $h) = $this->getTargetSize();
		return $this->loadFallbackImgOrColor($this->param('errorImg'), $this->param('errorColor'), $w, $h);
	}

	private function loadFallbackImgOrColor($imagePath, $fallbackColor, $fallbackW = 32, $fallbackH = 32)
	{
		$this->imageHandle = new Imagick();

		if (is_string($imagePath)) {
			if (!$this->imageHandle->readImage($imagePath)) {
				// throw exception if the configured fallback is incorrect
				throw new ImThumbException("Cannot display error image", self::ERR_CONFIGURATION);
			}
			$this->loadImageMeta($imagePath);
			$this->isValidSrc = false;
			return true;
		}

		if ($imagePath === false) {
			return false;
		}

		// not configured.. show coloured square
		$this->imageHandle->newImage($fallbackW, $fallbackH, new ImagickPixel($fallbackColor));
		$this->imageHandle->setFormat('jpg');
		$this->mimeType = 'image/jpeg';
		return false;
	}

	protected function getRealImagePath($src)
	{
		if (preg_match('@^https?://@i', $src)) {
			// :TODO:
			throw new ImThumbException('External images not implemented yet');
		}
		if ($this->param('baseDir')) {
			if (preg_match('@^' . preg_quote($this->param('baseDir'), '@') . '@', $src)) {
				return $src;
			}
			return realpath($this->param('baseDir') . '/' . $src);
		}

		require_once(dirname(__FILE__) . '/imthumb-requesthandler.class.php');
		return realpath(ImthumbRequestHandler::getDocRoot() . $src);
	}

	//--------------------------------------------------------------------------
	// Generation

	public function doResize()
	{
		if (!$this->isValidSrc) {
			// force showing the entire image, unaltered, if we are displaying a fallback
			$this->configureUnalteredFitImage();
		}

		// check if the image is OK to be handled first
		try {
			$isValid = $this->imageHandle->valid();
		} catch (ImagickException $e) {
			// :NOTE: this is a non-critical error because its main symptom is disabling
			// generated fallback images and so we need to jump out
			return false;
		}

		// get standard input properties
		$zoom_crop = (int)$this->param('cropMode');
		$quality = abs($this->param('quality'));
		$align = $this->param('align');
		$sharpen = (bool)$this->param('sharpen');

		list($new_width, $new_height) = $this->getTargetSize();

		// Get original width and height
		$width = $this->imageHandle->getImageWidth();
		$height = $this->imageHandle->getImageHeight();
		$origin_x = 0;
		$origin_y = 0;

		// generate new w/h if not provided
		if ($new_width && !$new_height) {
			$new_height = floor($height * ($new_width / $width));
		} else if ($new_height && !$new_width) {
			$new_width = floor($width * ($new_height / $height));
		}

		// GIFs need to have some extra handling done
		$isGIF = strpos($this->mimeType, 'gif') !== false;
		$isJPEG = strpos($this->mimeType, 'jpeg') !== false;
		$isPNG = strpos($this->mimeType, 'png') !== false;

		// are we dealing with transparency?
		$canvas_trans = (bool)$this->param('canvasTransparent') && ($isPNG || $isGIF);
		$canvas_color = $this->param('canvasColor');

		// perform requested cropping
		switch ($zoom_crop) {
			case 3:		// inner-fit
				$this->imageHandle->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1, true);
				break;
			case 2:		// inner-fill
				$this->imageHandle->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1, true);

				$canvas = $this->generateNewCanvas($new_width, $new_height, $canvas_trans ? null : $canvas_color, $this->mimeType);

				$xOffset = ($new_width - $this->imageHandle->getImageWidth()) / 2;
				$yOffset = ($new_height - $this->imageHandle->getImageHeight()) / 2;

				$canvas->compositeImage($this->imageHandle, Imagick::COMPOSITE_OVER, $xOffset, $yOffset);
				$this->imageHandle = $canvas;
				break;
			case 1:		// outer-fill
				// resize first to set scale as necessary
				$final_height = $height * ($new_width / $width);
				if ($final_height > $new_height) {
					$ratio = $new_width / $width;
				} else {
					$ratio = $new_height / $height;
				}
				$tempW = $width * $ratio;
				$tempH = $height * $ratio;

				$this->imageHandle->resizeImage($tempW, $tempH, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1);

				list($x, $y) = $this->getCropCoords($align, $tempW, $tempH, $new_width, $new_height);

				$this->imageHandle->cropImage($new_width, $new_height, $x, $y);
				break;
			default:	// exact dimensions
				$this->imageHandle->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1);
				break;
		}

		// process any configured image filters
		if ($this->param('filters')) {
			require_once(dirname(__FILE__) . '/imthumb-filters.php');

			$filterHandler = new ImThumbFilters($this->imageHandle);

			$filters = explode('|', $this->param('filters'));
			foreach ($filters as &$filterArgs) {
				$filterArgs = explode(',', $filterArgs);
				$filterName = trim(array_shift($filterArgs));

				try {
					if (is_numeric($filterName)) {
						// process TimThumb filters
						$filterHandler->timthumbFilter($filterName, $filterArgs);
					} else {
						// process Imagick filters
						call_user_func_array(array($filterHandler, $filterName), $filterArgs);
					}
				} catch (ImThumbException $e) {
					$this->critical("Problem with filter '{$filterName}': " . $e->getMessage());
				} catch (ImagickException $e) {
					$this->critical("Problem running filter '{$filterName}': " . $e->getMessage());
				}
			}
		}

		// GIFs need final page dimensions processed or they retain original image canvas size
		if ($isGIF) {
			$this->imageHandle->setImagePage($new_width, $new_height, 0, 0);
		}

		// set progressive JPEG if desired
		if ($isJPEG && $this->param('jpgProgressive')) {
			$this->imageHandle->setInterlaceScheme(Imagick::INTERLACE_PLANE);
		}

		// set background colour if PNG transparency is disabled
		if ($isPNG && $canvas_trans && !$this->param('pngTransparency')) {
			$canvas = $this->generateNewCanvas($new_width, $new_height, $canvas_color, $this->mimeType);
			$canvas->compositeImage($this->imageHandle, Imagick::COMPOSITE_OVER, 0, 0);
			$this->imageHandle = $canvas;
		}

		$this->compress();

		return true;
	}

	protected function generateNewCanvas($new_width, $new_height, $bgColor = null, $mimeType = 'image/jpeg')
	{
		$canvas = new Imagick();
		$canvas->newImage($new_width, $new_height, new ImagickPixel($bgColor ? '#' . $bgColor : 'transparent'));
		$canvas->setImageFormat(str_replace('image/', '', $mimeType));

		return $canvas;
	}

	protected function getTargetSize()
	{
		$w = min(abs($this->param('width')), $this->param('maxw'));
		$h = min(abs($this->param('height')), $this->param('maxh'));

		return array($w, $h);
	}

	protected function getCropCoords($align, $origW, $origH, $destW, $destH)
	{
		// read alignment for crop operation
		if (!$align || $align == 'c') {
			$gravity = 'center';
		} else if ($align == 'l') {
			$gravity = 'west';
		} else if ($align == 'r') {
			$gravity = 'east';
		} else {
			$gravity = '';
			if (strpos($align, 't') !== false) {
				$gravity .= 'north';
			}
			if (strpos($align, 'b') !== false) {
				$gravity .= 'south';
			}
			if (strpos($align, 'l') !== false) {
				$gravity .= 'west';
			}
			if (strpos($align, 'r') !== false) {
				$gravity .= 'east';
			}
		}

		// :NOTE: ImageMagick gravity doesn't work with cropping. A shame.
		$x = $y = 0;
		switch ($gravity) {
			case 'center':
				$x = ($origW - $destW) / 2;
				$y = ($origH - $destH) / 2;
				break;
			case 'northwest':
				break;
			case 'north':
				$x = ($origW - $destW) / 2;
				break;
			case 'northeast':
				$x = $origW - $destW;
				break;
			case 'west':
				$y = ($origH - $destH) / 2;
				break;
			case 'east':
				$x = $origW - $destW;
				$y = ($origH - $destH) / 2;
				break;
			case 'southwest':
				$x = 0;
				$y = $origH - $destH;
				break;
			case 'south':
				$x = ($origW - $destW) / 2;
				$y = $origH - $destH;
				break;
			case 'southeast':
				$x = $origW - $destW;
				$y = $origH - $destH;
				break;
		}

		return array($x, $y);
	}

	protected function compress()
	{
		if (strpos($this->mimeType, 'jpeg') !== false) {
			if ($this->param('quality') == 100) {
				$this->imageHandle->setImageCompression(Imagick::COMPRESSION_LOSSLESSJPEG);
			} else {
				$this->imageHandle->setImageCompression(Imagick::COMPRESSION_JPEG);
			}
		} else if (strpos($this->mimeType, 'png') !== false) {
			$this->imageHandle->setImageCompression(Imagick::COMPRESSION_ZIP);
		}

		$this->imageHandle->setImageCompressionQuality((double)$this->param('quality'));
		$this->imageHandle->stripImage();
	}

	//--------------------------------------------------------------------------
	// Output

	public function sendHeaders($serverErrorString = null)
	{
		// check we can send these first
		if (headers_sent()) {
			$this->critical("Could not set image headers, output already started", self::ERR_OUTPUTTING);
		}

		// avoid timezone setting warnings
		date_default_timezone_set(@date_default_timezone_get());

		$modifiedDate = gmdate('D, d M Y H:i:s') . ' GMT';

		// get image size. Have to workaround bugs in Imagick that return 0 for size by counting ourselves.
		$byteSize = $this->getImageSize();

		if ($this->hasBrowserCache()) {
			header('HTTP/1.0 304 Not Modified');
		} else {
			if ($serverErrorString) {
				header('HTTP/1.0 500 Internal Server Error');
				header('X-ImThumb-Error: ' . $serverErrorString);
			} else if (!$this->isValidSrc) {
				header('HTTP/1.0 404 Not Found');
			} else {
				header('HTTP/1.0 200 OK');
			}
			header('Content-Type: ' . $this->mimeType);
			header('Accept-Ranges: none');
			header('Last-Modified: ' . $modifiedDate);
			header('Content-Length: ' . $byteSize);

			if (!$this->param('browserCache')) {
				header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
				header("Pragma: no-cache");
				header('Expires: ' . gmdate('D, d M Y H:i:s', time()));
			} else {
				$maxAge = $this->param('browserCacheMaxAge');
				$expiryDate = gmdate('D, d M Y H:i:s', strtotime('now +' . $maxAge . ' seconds')) . ' GMT';
				header('Cache-Control: max-age=' . $maxAge . ', must-revalidate');
				header('Expires: ' . $expiryDate);
			}
		}

		// informational headers
		if (!$this->param('silent')) {
			$imVer = Imagick::getVersion();
			header('X-Generator: ImThumb v' . self::VERSION . '; ' . $imVer['versionString']);

			if ($this->hasCache) {
				header('X-Img-Cache: HIT');
			} else {
				header('X-Img-Cache: MISS');
			}
			if ($this->param('debug')) {
				header('X-Generated-In: ' . number_format(microtime(true) - $this->startTime, 6) . 's');
				header('X-Memory-Peak: ' . number_format((memory_get_peak_usage() / 1024), 3, '.', '') . 'KB');

				$data = getrusage();
				$memusage = array((double)($data['ru_utime.tv_sec'] + $data['ru_utime.tv_usec'] / 1000000), (double)($data['ru_stime.tv_sec'] + $data['ru_stime.tv_usec'] / 1000000));
				header('X-CPU-Utilisation:'
					.  ' Usr ' . number_format($memusage[0] - $this->startCPU[0], 6)
					. ', Sys ' . number_format($memusage[1] - $this->startCPU[1], 6)
					. '; Base ' . number_format($this->startCPU[0], 6) . ', ' . number_format($this->startCPU[1], 6));
			}
		}
	}

	public function display()
	{
		$this->sendHeaders();

		$str = $this->getImage();
		echo $str;

		return $str ? true : false;
	}

	public function getImage()
	{
		$cachePath = $this->getCachePath();

		if ($cachePath && file_exists($cachePath)) {
			return file_get_contents($cachePath);
		}

		try {
			return $this->imageHandle->getImageBlob();
		} catch (ImagickException $e) {
			return false;
		}
	}

	public function getImageSize()
	{
		$data = $this->getImage();

		if (self::$HAS_MBSTRING && (self::$MBSTRING_SHADOW & 2)) {
			return mb_strlen($data, 'latin1');
		} else {
			return strlen($data);
		}
	}

	public function hasBrowserCache($modifiedSince = null)
	{
		if (!$modifiedSince) {
			$modifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : false;
		}

		if ($this->mtime <= 0 || !$this->param('browserCache') || empty($modifiedSince)) {
			return false;
		}

		if (!is_numeric($modifiedSince)) {
			$modifiedSince = strtotime($modifiedSince);
		}

		if ($modifiedSince < $this->mtime) {
			return false;
		}
		return true;
	}

	//--------------------------------------------------------------------------
	// Caching

	public function initCacheDir()
	{
		$cacheDir = $this->param('cache');
		if (!$cacheDir) {
			return;
		}

		if (!touch($cacheDir . '/index.html')) {
			$this->critical("Could not create the index.html file - to fix this create an empty file named index.html file in the cache directory.", self::ERR_CACHE);
		}
	}

	public function writeToCache()
	{
		if ($this->hasCache || !$this->param('cache')) {
			return false;
		}

		$this->initCacheDir();

		$tempfile = tempnam($this->param('cache'), 'imthumb_tmpimg_');

		if (!$this->imageHandle->writeImage($tempfile)) {
			$this->critical("Could not write image to temporary file", self::ERR_CACHE);
		}

		$cacheFile = $this->getCachePath();
		$lockFile = $cacheFile . '.lock';

		$fh = fopen($lockFile, 'w');
		if (!$fh) {
			$this->critical("Could not open the lockfile for writing an image", self::ERR_CACHE);
		}

		if (flock($fh, LOCK_EX)) {
			@unlink($cacheFile);
			rename($tempfile, $cacheFile);
			flock($fh, LOCK_UN);
			fclose($fh);
			@unlink($lockFile);
		} else {
			fclose($fh);
			@unlink($lockFile);
			@unlink($tempfile);
			$this->critical("Could not get a lock for writing", self::ERR_CACHE);
		}

		return true;
	}

	public function checkExpiredCaches()
	{
		$cacheDir = $this->param('cache');
		$cacheExpiry = $this->param('cacheCleanPeriod');
		if (!$cacheDir || !$cacheExpiry || $cacheExpiry < 0) {
			return;
		}

		$lastCleanFile = $cacheDir . '/timthumb_cacheLastCleanTime.touch';

		// If this is a new installation we need to create the file
		if (!is_file($lastCleanFile)) {
			if (!touch($lastCleanFile)) {
				$this->critical("Could not create cache clean timestamp file.", self::ERR_CACHE);
			}
		}

		// check for last auto-purge time
		if (@filemtime($lastCleanFile) < (time() - $cacheExpiry)) {
			// :NOTE: (from timthumb) Very slight race condition here, but worst case we'll have 2 or 3 servers cleaning the cache simultaneously once a day.
			if (!touch($lastCleanFile)) {
				$this->error("Could not create cache clean timestamp file.");
			}

			$maxAge = $this->param('cacheMaxAge');
			$files = glob($this->cacheDirectory . '/*' . $this->param('cacheSuffix'));

			if ($files) {
				$timeAgo = time() - $maxAge;
				foreach ($files as $file) {
					if (@filemtime($file) < $timeAgo) {
						@unlink($file);
					}
				}
			}
			return true;
		}

		return false;
	}

	private function getCachePath()
	{
		$cacheDir = $this->param('cache');

		if (!$cacheDir) {
			return false;
		}

		if ($this->param('cacheFilenameFormat')) {
			list($width, $height) = $this->getTargetSize();

			return $cacheDir . '/' . str_replace(array(
				'%filename%',
				'%ext%',
				'%w%',
				'%h%',
				'%q%',
				'%a%',
				'%zc%',
				'%s%',
				'%cc%',
				'%ct%',
				'%filters%',
				'%pjpg%',
			), array(
				basename($this->src, '.' . $this->imageExt),
				$this->imageExt,
				$width,
				$height,
				$this->param('quality'),
				$this->param('align'),
				$this->param('cropMode'),
				$this->param('sharpen') ? 's' : '',
				$this->param('canvasColor'),
				$this->param('canvasTransparent') ? 't' : '',
				$this->param('filters'),
				$this->param('jpgProgressive') ? 'p' : '',
			), $this->param('cacheFilenameFormat'));
		}

		return $cacheDir . '/' . $this->param('cachePrefix') . md5($this->param('cacheSalt') . implode('', $this->params) . self::VERSION) . $this->param('cacheSuffix');
	}

	//--------------------------------------------------------------------------
	// Rate limiting

	/**
	 * @return true if rate is OK, false if exceeded
	 */
	public function checkRateLimits()
	{
		if ($limiter = $this->param('rateLimiter')) {
			$limiter->setGenerator($this);
			if (!$limiter->checkRateLimits()) {
				throw new ImThumbCriticalException($this->param('rateExceededMessage'), ImThumb::ERR_RATE_EXCEEDED);
			}
		}
	}

	//--------------------------------------------------------------------------
	// Error handling

	protected function critical($string, $code = 0)
	{
		throw new ImThumbException($string, $code);
	}
}

ImThumb::$HAS_MBSTRING = extension_loaded('mbstring');
ImThumb::$MBSTRING_SHADOW = (int)ini_get('mbstring.func_overload');
