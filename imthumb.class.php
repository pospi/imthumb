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

	const DEFAULT_SIZE = 100;		// if invalid dimensions are passed, this will be used

	//--------------------------------------------------------------------------
	// Initialisation & configuration

	public static $defaultImageSource = 'ImThumbSource_Local';

	private $params;
	public $meta;	// ImThumbMeta instance

	private $imageHandle = null;

	public $cache;			// ImThumbCache instance

	public $sourceHandler;	// ImThumbSource instance

	private $startTime;	// stats
	private $startCPU;


	public function __construct(Array $params = null)
	{
		if (!class_exists('Imagick')) {
			throw new ImThumbException("Could not load ImThumb: ImageMagick is not installed. Please contact your webhost and ask them to install the ImageMagick library", self::ERR_SERVER_CONFIG);
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
		if (!$this->param('width') && !$this->param('height') && !$this->params('cropRect')) {
			$this->params['width'] = $this->params['height'] = self::DEFAULT_SIZE;
		}
	}

	public function __destruct()
	{
		if ($this->imageHandle) {
			$this->imageHandle->destroy();
			unset($this->imageHandle);
		}
	}

	// -- simple accessors --

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

	public function isValid()
	{
		if (!isset($this->meta)) {
			return false;
		}
		return $this->meta->valid;
	}

	public function getMime()
	{
		if (!isset($this->meta)) {
			return null;
		}
		return $this->meta->mimeType;
	}

	public function getSrc()
	{
		return $this->meta->src;
	}

	public function getImagick()
	{
		return $this->imageHandle;
	}

	public function getTimingStats()
	{
		return array(
			$this->startTime,
			$this->startCPU[0],
			$this->startCPU[1]
		);
	}

	// -- simple mutators --

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
			$this->params['cropRect'],
			$this->params['jpgProgressive']
		);

		$this->params['width'] = self::DEFAULT_SIZE;
		$this->params['height'] = self::DEFAULT_SIZE;
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

		// find appropriate handler for this image
		if (!$this->determineImageSource($src)) {
			// fallback to default source handling (local filesystem unless overridden)
			$this->setDefaultSourceHandler();
		}

		// read image metadata
		$this->meta = $this->sourceHandler->readMetadata($src, $this);

		// validate it to ensure processable
		$this->meta->validateWith($this);

		// check the cache and flag for generation where necessary
		$this->cache = null;
		if ($this->initCache()) {
			// cache ftw. load it up
			$this->cache->load($this);

			// check cache time against file time.
			if ($this->meta->mtime > $this->cache->mtime) {
				// if cache has been invalidated, flag it as empty so we regenerate on our next call to writeCache()
				$this->cache->isCached(false);
			} else {
				// otherwise we have no need to do anything else, cached image is already loaded
				return;
			}
		}

		// load the source image into memory
		$this->imageHandle = $this->sourceHandler->readResource($this->meta, $this);

		// load up the fallback image if we couldn't find the source
		if (!$this->imageHandle) {
			$this->loadFallbackImage();
		}

		// process the action
		$this->doResize();
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

			$this->setDefaultSourceHandler();
			$this->sourceHandler->readMetadata($imagePath, $this);
			$this->meta->valid = false;
			return true;
		}

		if ($imagePath === false) {
			return false;
		}

		if (!$fallbackW) {
			$fallbackW = self::DEFAULT_SIZE;
		}
		if (!$fallbackH) {
			$fallbackH = self::DEFAULT_SIZE;
		}

		// not configured.. show coloured square
		$this->imageHandle->newImage($fallbackW, $fallbackH, new ImagickPixel($fallbackColor));
		$this->imageHandle->setImageFormat('jpg');

		$this->meta->mimeType = 'image/jpeg';
		return false;
	}

	protected function determineImageSource($src)
	{
		if ($handlerClass = $this->checkExternalSource($src)) {
			$this->sourceHandler = new $handlerClass();
			return true;
		}
		return false;
	}

	protected function setDefaultSourceHandler()
	{
		if (!self::loadSourceHandler(self::$defaultImageSource)) {
			throw new ImThumbCriticalException('Could not load default source handler! Is ImThumb installed correctly?', self::ERR_SERVER_CONFIG);
		}
		$hClass = self::$defaultImageSource;	// oldPHP
		$this->sourceHandler = new $hClass();
	}

	//--------------------------------------------------------------------------
	// Generation

	public function doResize()
	{
		if (!$this->isValid()) {
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

		// each filetype needs to have some extra handling done
		$isGIF = strpos($this->meta->mimeType, 'gif') !== false;
		$isJPEG = strpos($this->meta->mimeType, 'jpeg') !== false;
		$isPNG = strpos($this->meta->mimeType, 'png') !== false;

		// are we dealing with transparency?
		$canvas_trans = (bool)$this->param('canvasTransparent') && ($isPNG || $isGIF);
		$canvas_color = $this->param('canvasColor');

		list($new_width, $new_height) = $this->getTargetSize();

		// run crop / resize operation
		if ($this->param('cropRect')) {
			// Explicitly set crop coordinates.
			// If width and height are provided, the resulting image is adjusted to fit within those dimensions as normal.
			@list($startX, $startY, $endX, $endY) = explode(',', $this->param('cropRect'));
			$this->processResizeCrop($new_width, $new_height, $startX, $startY, $endX, $endY,
									(int)$this->param('cropMode'), $this->param('align'),
									(bool)$this->param('sharpen'), $canvas_trans, $canvas_color);
		} else {
			// TimThumb style simplified crop operation
			$this->processZoomCrop($new_width, $new_height,
									(int)$this->param('cropMode'), $this->param('align'),
									(bool)$this->param('sharpen'), $canvas_trans, $canvas_color);
		}

		// process any configured image filters
		if ($this->param('filters')) {
			$this->runFilters($this->param('filters'));
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
			$canvas = $this->generateNewCanvas($new_width, $new_height, $canvas_color, $this->meta->mimeType);
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

	protected function processZoomCrop($new_width, $new_height, $zoom_crop = 3, $align = 'c', $sharpen = false, $canvas_trans = true, $canvas_color = 'ffffff')
	{
		list($width, $height, $new_width, $new_height) = $this->getSourceAndTargetDims($new_width, $new_height);

		if ($width == $new_width && $height == $new_height) {
			// already the correct size
			return;
		}

		// perform requested cropping
		switch ($zoom_crop) {
			case 3:		// inner-fit
				$new_width = min($new_width, $width);
				$new_height = min($new_height, $height);
				$this->imageHandle->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1, true);
				break;
			case 2:		// inner-fill
				$this->imageHandle->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, $sharpen ? 0.7 : 1, true);

				$canvas = $this->generateNewCanvas($new_width, $new_height, $canvas_trans ? null : $canvas_color, $this->meta->mimeType);

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
	}

	protected function processResizeCrop($new_width, $new_height, $startX, $startY, $endX, $endY, $zoom_crop = 3, $align = 'c', $sharpen = false, $canvas_trans = true, $canvas_color = 'ffffff')
	{
		list($width, $height, $new_width, $new_height) = $this->getSourceAndTargetDims($new_width, $new_height);

		if ($startX < 0 || $startY < 0 || $endX > $width || $endY > $height) {
			// if crop coords are outside of original image, we will need to add a background
			$cropW = abs($endX - $startX);
			$cropH = abs($endY - $startY);

			$canvas = $this->generateNewCanvas($cropW, $cropH, $canvas_trans ? null : $canvas_color, $this->meta->mimeType);

			$xOffset = $startX < 0 ? abs($startX) : 0;
			$yOffset = $startY < 0 ? abs($startY) : 0;

			$startX = max($startX, 0);
			$endX = min($endX, $width);
			$startY = max($startY, 0);
			$endY = min($endY, $height);

			$this->imageHandle->cropImage(abs($endX - $startX), abs($endY - $startY), $startX, $startY);
			$canvas->compositeImage($this->imageHandle, Imagick::COMPOSITE_OVER, $xOffset, $yOffset);

			$this->imageHandle = $canvas;
		} else {
			// otherwise we can just crop
			$this->imageHandle->cropImage(abs($endX - $startX), abs($endY - $startY), $startX, $startY);
		}

		// handle the adjustments for W/H inside the target dimensions if the cropped area is not the size of the specified output
		$this->processZoomCrop($new_width, $new_height, $zoom_crop, $align, $sharpen, $canvas_trans, $canvas_color);
	}

	protected function runFilters($filters)
	{
		require_once(dirname(__FILE__) . '/imthumb-filters.php');

		$filterHandler = new ImThumbFilters($this->imageHandle);

		$filters = explode('|', $filters);
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
				throw new ImThumbException("Problem running filter '{$filterName}': " . $e->getMessage());
			} catch (ImagickException $e) {
				throw new ImThumbException("Error in filter '{$filterName}': " . $e->getMessage());
			}
		}
	}

	protected function compress()
	{
		if (strpos($this->meta->mimeType, 'jpeg') !== false) {
			if ($this->param('quality') == 100) {
				$this->imageHandle->setImageCompression(Imagick::COMPRESSION_LOSSLESSJPEG);
			} else {
				$this->imageHandle->setImageCompression(Imagick::COMPRESSION_JPEG);
			}
		} else if (strpos($this->meta->mimeType, 'png') !== false) {
			$this->imageHandle->setImageCompression(Imagick::COMPRESSION_ZIP);
		}

		$this->imageHandle->setImageCompressionQuality((double)$this->param('quality'));
		$this->imageHandle->stripImage();
	}

	//--------------------------------------------------------------------------

	public function getTargetSize()
	{
		$w = $this->param('width');
		$h = $this->param('height');

		if (!$w && !$h && $this->param('cropRect')) {
			// if there are no target dimensions but there is a crop rect, use that size
			@list($startX, $startY, $endX, $endY) = explode(',', $this->param('cropRect'));
			$w = min(abs($endX - $startX), $this->param('maxw'));
			$h = min(abs($endY - $startY), $this->param('maxh'));
		} else {
			$w = min(abs($w), $this->param('maxw'));
			$h = min(abs($h), $this->param('maxh'));
		}

		if (!$w && !$h) {
			$w = $h = self::DEFAULT_SIZE;
		}

		return array($w, $h);
	}

	protected function getSourceAndTargetDims($new_width, $new_height)
	{
		// Get original width and height
		$width = $this->imageHandle->getImageWidth();
		$height = $this->imageHandle->getImageHeight();

		// generate new w/h if not provided
		if ($new_width && !$new_height) {
			$new_height = floor($height * ($new_width / $width));
		} else if ($new_height && !$new_width) {
			$new_width = floor($width * ($new_height / $height));
		}

		if (!$width && !$height) {
			$width = $height = self::DEFAULT_SIZE;
		}

		return array($width, $height, $new_width, $new_height);
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

	//--------------------------------------------------------------------------
	// Output

	public function getImage()
	{
		$cachedImage = $this->cache ? $this->cache->getImage($this) : false;

		if ($cachedImage) {
			return $cachedImage;
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

	//--------------------------------------------------------------------------
	// Caching

	public function initCache()
	{
		if (!($cachePath = $this->param('cache'))) {
			return false;
		}

		require_once(dirname(__FILE__) . '/imthumb-cache.class.php');

		$this->cache = new ImThumbCache($cachePath, array(
			'cacheCleanPeriod' => $this->param('cacheCleanPeriod'),
			'cacheMaxAge' => $this->param('cacheMaxAge'),

			'cachePrefix' => $this->param('cachePrefix'),
			'cacheSalt' => $this->param('cacheSalt'),
			'cacheSuffix' => $this->param('cacheSuffix'),
			'cacheFilenameFormat' => $this->param('cacheFilenameFormat'),
		));

		return true;
	}

	public function getCache()
	{
		return $this->cache;
	}

	public function writeCache()
	{
		if (!$this->cache) {
			return false;
		}

		return $this->cache->write($this);
	}

	public function hasbrowserCache($modifiedSince = null)
	{
		if (!$modifiedSince) {
			$modifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : false;
		}

		if (!$this->meta || $this->meta->mtime <= 0 || !$this->param('doBrowserCache') || empty($modifiedSince)) {
			return false;
		}

		if (!is_numeric($modifiedSince)) {
			$modifiedSince = strtotime($modifiedSince);
		}

		return $modifiedSince >= $this->meta->mtime;
	}

	//--------------------------------------------------------------------------
	// External assets

	public function checkExternalSource($src)
	{
		// no whitelist or global permission, can't do it
		if (!$whitelist = $this->params['sourceHandlers']) {
			return false;
		}

		// process all URI matches & return an appropriate handler if one is found
		foreach ($whitelist as $uri => $handlerClass) {
			if (preg_match($uri, $src)) {
				if (!self::loadSourceHandler($handlerClass)) {
					throw new ImThumbCriticalException('Could not load source handler \'' . $handlerClass . '\'', self::ERR_SERVER_CONFIG);
				}
				return $handlerClass;
			}
		}

		return false;
	}

	public static function loadSourceHandler($class)
	{
		if (class_exists($class)) {
			return true;
		}

		$tryFile = '/imthumb-source-' . strtolower(str_replace('ImThumbSource_', '', $class)) . '.class.php';

		if (file_exists(dirname(__FILE__) . $tryFile)) {
			require_once(dirname(__FILE__) . $tryFile);
			return true;
		}
		if ($this->params['extraSourceHandlerPath'] && file_exists($this->params['extraSourceHandlerPath'] . $tryFile)) {
			require_once($this->params['extraSourceHandlerPath'] . $tryFile);
			return true;
		}
		return false;
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
}

ImThumb::$HAS_MBSTRING = extension_loaded('mbstring');
ImThumb::$MBSTRING_SHADOW = (int)ini_get('mbstring.func_overload');
