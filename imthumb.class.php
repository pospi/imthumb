<?php
/**
 * Main image generation class
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-10-18
 */

class ImThumb
{
	const VERSION = 1;

	const ERR_SERVER_CONFIG = 1;	// exception codes
	const ERR_SRC_IMAGE = 2;
	const ERR_OUTPUTTING = 3;
	const ERR_CACHE = 4;

	public static $HAS_MBSTRING;	// used for reliable image length determination. Initialised below class def'n.
	public static $MBSTRING_SHADOW;

	//--------------------------------------------------------------------------
	// Request handler

	public static function processRequest()
	{
		// build params for the class
		$params = array(
			'src' => self::readParam('src'),
			'width' => self::readParam('w', null),
			'height' => self::readParam('h', null),
			'quality' => self::readParam('q', self::readConst('DEFAULT_Q', 90)),
			'align' => self::readParam('a', 'c'),
			'cropMode' => self::readParam('zc', self::readConst('DEFAULT_ZC', 1)),
			'sharpen' => self::readParam('s', self::readConst('DEFAULT_S', 0)),
			'canvasColor' => self::readParam('cc', self::readConst('DEFAULT_CC', 'ffffff')),
			'canvasTransparent' => (bool)self::readParam('ct', true),

			'fallbackImg' => self::readConst('NOT_FOUND_IMAGE'),
			'errorImg' => self::readConst('ERROR_IMAGE'),
			'pngTransparency' => self::readConst('PNG_IS_TRANSPARENT', false),

			'maxw' => self::readConst('MAX_WIDTH', 1500),
			'maxh' => self::readConst('MAX_HEIGHT', 1500),

			'maxSize' => self::readConst('MAX_FILE_SIZE', 10485760),
			'externalAllowed' => !self::readConst('BLOCK_EXTERNAL_LEECHERS', false),

			'baseDir' => self::readConst('IMTHUMB_BASEDIR'),

			'cache' => self::readConst('FILE_CACHE_ENABLED', true) ? self::readConst('FILE_CACHE_DIRECTORY', './cache') : false,
			'cachePrefix' => self::readConst('FILE_CACHE_PREFIX', 'timthumb'),
			'cacheSuffix' => self::readConst('FILE_CACHE_SUFFIX', '.timthumb.txt'),
			'cacheMaxAge' => self::readConst('FILE_CACHE_MAX_FILE_AGE', 86400),
			'cacheCleanPeriod' => self::readConst('FILE_CACHE_TIME_BETWEEN_CLEANS', 86400),
			'cacheSalt' => self::readConst('FILE_CACHE_NAME_SALT', 'IOLUJN!(Y&)(TEHlsio(&*Y3978fgsdBBu'),
			'browserCache' => !self::readConst('BROWSER_CACHE_DISABLE', false),
			'browserCacheMaxAge' => self::readConst('BROWSER_CACHE_MAX_AGE', 86400),
		);

		// create image handler
		try {
			$handler = new ImThumb($params);
		} catch (Exception $e) {
			if ($e->getCode() == self::ERR_SRC_IMAGE) {
				// log the querystring passed in addition to regular message
				throw new Exception($e->getMessage() . ' Source querystring: ' . $_SERVER['QUERY_STRING'], self::ERR_SRC_IMAGE);
			} else {
				throw $e;
			}
		}

		// process request steps in order
		$handler->writeToCache();
		$handler->display();
	}

	private static function readParam($name, $default = null)
	{
		return isset($_GET[$name]) ? $_GET[$name] : $default;
	}

	private static function readConst($name, $default = null)
	{
		return defined($name) ? constant($name) : $default;
	}

	//--------------------------------------------------------------------------
	// Initialisation & configuration

	private $params;

	private $imageHandle = null;
	private $imageType;
	private $mimeType;
	private $imageExt;

	private $hasCache = false;

	public function __construct(Array $params = null)
	{
		if (!class_exists('Imagick')) {
			$this->critical("Could not load ImThumb: ImageMagick is not installed. Please contact your webhost and ask them to install the ImageMagick library", self::ERR_SERVER_CONFIG);
		}

		if (defined('MEMORY_LIMIT') && MEMORY_LIMIT) {
			ini_set('memory_limit', MEMORY_LIMIT);
		}

		ksort($params);	// :NOTE: order the parameters to get consistent cache filenames, as this is factored into filename hashes
		$this->params = $params;

		$src = $this->param('src');
		if ($src && $this->param('baseDir')) {
			$src = $this->param('baseDir') . '/' . $src;
		}

		if (!$this->param('width') && !$this->param('height')) {
			$this->params['width'] = $this->params['height'] = 100;
		}

		if ($src) {
			if ($this->param('cache') && file_exists($this->getCachePath())) {
				$this->hasCache = true;
				$this->loadImageMeta($src);
			} else {
				$this->loadImage($src);
				$this->doResize();
			}
		}

		if (!$src) {
			$this->critical("No image path specified for thumbnail generation", self::ERR_SRC_IMAGE);
		}

		$this->initCacheDir();
		$this->checkExpiredCaches();
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

	//--------------------------------------------------------------------------
	// Loading

	public function loadImage($src)
	{
		$this->loadImageMeta($src);
		$this->imageHandle = new Imagick();

		if ($this->mimeType == 'image/jpg') {
			$this->imageHandle->setFormat('jpg');
		} else if ($this->mimeType == 'image/gif') {
			$this->imageHandle->setFormat('gif');
		} else if ($this->mimeType == 'image/png') {
			$this->imageHandle->setFormat('png');
		}

		$this->imageHandle->readImage($src);
	}

	protected function loadImageMeta($src)
	{
		$sData = getimagesize($src);
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

	//--------------------------------------------------------------------------
	// Generation

	public function doResize()
	{
		// get standard input properties
		$new_width = abs($this->param('width'));
		$new_height = abs($this->param('height'));
		$zoom_crop = (int)$this->param('cropMode');
		$quality = abs($this->param('quality'));
		$align = $this->param('align');
		$sharpen = (bool)$this->param('sharpen');

		// ensure size limits can not be abused
		$new_width = min($new_width, $this->param('maxw'));
		$new_height = min($new_height, $this->param('maxh'));

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

		// perform requested cropping
		switch ($zoom_crop) {
			case 3:		// inner-fit
				$this->imageHandle->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1, true);
				break;
			case 2:		// inner-fill
				$canvas_color = $this->param('canvasColor');
				$canvas_trans = (bool)$this->param('canvasTransparent') && false !== strpos($this->mimeType, 'png');

				$this->imageHandle->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1, true);

				$canvas = new Imagick();
				$canvas->newImage($new_width, $new_height, new ImagickPixel($canvas_trans ? 'transparent' : "#" . $canvas_color));
				$canvas->setImageFormat(str_replace('image/', '', $this->mimeType));

				$xOffset = ($new_width - $this->imageHandle->getImageWidth()) / 2;
				$yOffset = ($new_height - $this->imageHandle->getImageHeight()) / 2;

				$canvas->compositeImage($this->imageHandle, Imagick::COMPOSITE_OVER, $xOffset, $yOffset);
				unset($this->imageHandle);
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

		if ($isGIF) {
			$this->imageHandle->setImagePage($new_width, $new_height, 0, 0);
		}

		$this->compress();
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

	public function sendHeaders()
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

	public function display()
	{
		$this->sendHeaders();

		echo $this->getImage();
	}

	public function getImage()
	{
		$cachePath = $this->getCachePath();

		if ($cachePath && file_exists($cachePath)) {
			return file_get_contents($cachePath);
		}

		return $this->imageHandle->getImageBlob();
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
			return;
		}

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

		return $cacheDir . '/' . $this->param('cachePrefix') . md5($this->param('cacheSalt') . implode('', $this->params) . self::VERSION) . $this->param('cacheSuffix');
	}

	//--------------------------------------------------------------------------
	// Error handling

	protected function critical($string, $code = 0)
	{
		throw new Exception('ImThumb: ' . $string, $code);
	}
}

ImThumb::$HAS_MBSTRING = extension_loaded('mbstring');
ImThumb::$MBSTRING_SHADOW = (int)ini_get('mbstring.func_overload');
