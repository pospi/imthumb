<?php
/**
 * ImThumb filter wrapper class
 *
 * Basically provides a safe wrapper around ImageMagick methods that are whitelisted
 * for calling via HTTP.
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-11-04
 */

class ImThumbFilters
{
	// image filter constants from TimThumb mapped to Imagick filter methods and default params
	private static $IMAGE_FILTER_IDS = array(
		1 => array('negateImage',	array(0)),
		2 => array('modulateImage', array(100, 0, 100)),	// greyscale
		3 => array('modulateImage', array(100, 100, 100)),	// brightness
		4 => array('levelImage',	array(0, 1, 0)),		// contrast
		5 => array('colorizeImage', array('#FF0000', 1)),	// colorize
		6 => array('edgeImage', 	array(0.001)),			// detect edges
		7 => array('embossImage', 	array(0, 0.5)),		// emboss
		8 => array('gaussianBlurImage', array(0, 0.5)),	// gaussian blur
		9 => array('blurImage',		array(0, 0.5)),		// selective blur
		10 => array('sketchImage',	array(15,10,45)),	// image mean removal..?
		11 => array('medianFilterImage', array(2)),		// smooth
	);

	// image filter parameter lists from ImageMagick
	private static $IMAGE_FILTER_PARAMS = array(
		'adaptiveBlurImage'				=> 0,
		'adaptiveResizeImage'			=> 0,
		'adaptiveSharpenImage'			=> 0,
		'adaptiveThresholdImage'		=> 0,
		'addNoiseImage'					=> 0,
		'affinetransformimage'			=> 0,
		'annotateImage'					=> 0,
		'averageImages'					=> 0,
		'blackThresholdImage'			=> 0,
		'blurImage'						=> 0,
		'borderImage'					=> 0,
		'charcoalImage'					=> 0,
		'chopImage'						=> 0,
		'clipImage'						=> 0,	// :NOTE:	I just realised I don't have to document paramsets
		'clipPathImage'					=> 0,	//			because the errors will sort themselves out. You're on your
		'coalesceImages'				=> 0,	//			own for now, read the docs. soz: http://us2.php.net/manual/en/class.imagick.php#imagick.imagick.methods
		'colorFloodFillImage'			=> 0,
		'colorizeImage'					=> 0,
		'combineImages'					=> 0,
		'compareImageChannels'			=> 0,
		'compareImageLayers'			=> 0,
		'compositeImage'				=> 0,
		'contrastImage'					=> 0,
		'contrastStretchImage'			=> 0,
		'convolveImage'					=> 0,
		'cropImage'						=> 0,
		'cycleColormapImage'			=> 0,
		'deconstructImages'				=> 0,
		'drawImage'						=> 0,
		'edgeImage'						=> 1,	// detection radius
		'embossImage'					=> 2,	// radius, sigma
		'enhanceImage'					=> 0,
		'equalizeImage'					=> 0,
		'evaluateImage'					=> 0,
		'flattenImages'					=> 0,
		'flipImage'						=> 0,
		'flopImage'						=> 0,
		'fxImage'						=> 0,
		'gammaImage'					=> 0,
		'gaussianBlurImage'				=> 3,	// radius, sigma, channel
		'implodeImage'					=> 0,
		'levelImage'					=> 0,
		'linearStretchImage'			=> 0,
		'magnifyImage'					=> 0,
		'matteFloodFillImage'			=> 0,
		'medianFilterImage'				=> 1,	// radius
		'minifyImage'					=> 0,
		'modulateImage'					=> 0,
		'montageImage'					=> 0,
		'morphImages'					=> 0,
		'mosaicImages'					=> 0,
		'motionBlurImage'				=> 0,
		'negateImage'					=> 0,
		'normalizeImage'				=> 0,
		'oilPaintImage'					=> 0,
		'optimizeImageLayers'			=> 0,
		'paintOpaqueImage'				=> 0,
		'paintTransparentImage'			=> 0,
		'posterizeImage'				=> 0,
		'radialBlurImage'				=> 0,
		'raiseImage'					=> 0,
		'randomThresholdImage'			=> 0,
		'reduceNoiseImage'				=> 0,
		'render'						=> 0,
		'resampleImage'					=> 0,
		'resizeImage'					=> 0,
		'rollImage'						=> 0,
		'rotateImage'					=> 0,
		'sampleImage'					=> 0,
		'scaleImage'					=> 0,
		'separateImageChannel'			=> 0,
		'sepiaToneImage'				=> 0,
		'shadeImage'					=> 0,
		'shadowImage'					=> 0,
		'sharpenImage'					=> 0,
		'shaveImage'					=> 0,
		'shearImage'					=> 0,
		'sigmoidalContrastImage'		=> 0,
		'sketchImage'					=> 3,	// radius, sigma, angle
		'solarizeImage'					=> 0,
		'spliceImage'					=> 0,
		'spreadImage'					=> 0,
		'steganoImage'					=> 0,
		'stereoImage'					=> 0,
		'stripImage'					=> 0,
		'swirlImage'					=> 0,
		'textureImage'					=> 0,
		'thresholdImage'				=> 0,
		'thumbnailImage'				=> 0,
		'tintImage'						=> 0,
		'transverseImage'				=> 0,
		'trimImage'						=> 0,
		'uniqueImageColors'				=> 0,
		'unsharpMaskImage'				=> 0,
		'vignetteImage'					=> 0,
		'waveImage'						=> 0,
		'whiteThresholdImage'			=> 0,
	);

	private $handle;

	public function __construct(Imagick $instance)
	{
		$this->handle = $instance;
	}

	public function __destruct()
	{
		unset($this->handle);
	}

	//--------------------------------------------------------------------------

	/**
	 * Process all filter methods against our Imagick instance. Acts
	 * as a method call whitelist, basically.
	 */
	public function __call($method, $args)
	{
		if (!isset(self::$IMAGE_FILTER_PARAMS[$method])) {
			throw new ImThumbException("{$method} is not a valid filter name");
		}

		// auto-read quantumn range for methods needing it
		if ($method == 'levelImage' & empty($args[2])) {
			$qRange = $this->handle->getQuantumRange();
			$qRange = $qRange['quantumRangeLong'];
			$args[2] = $qRange;
		}

		return call_user_func_array(array($this->handle, $method), $args);
	}

	/**
	 * Process a timthumb filter by its numeric ID
	 */
	public function timthumbFilter($id, $args)
	{
		if (!isset(self::$IMAGE_FILTER_IDS[$id])) {
			throw new ImThumbException("{$method} is not a valid filter ID");
		}

		// adjust some parameters to timthumb argument expectations
		if (isset($args[0])) {
			if ($id == 3) {				// brightness. Imagick is normalised at 100, GD normalised at 0.
				$args[0] += 100;
			} else if ($id == 4) {		// contrast. Imagick uses 0 - 100, GD uses -100 to 100 linearly.
				if ($args[0] <= 0) {
					$gamma = ($args[0] / 100) + 1;
				} else {
					$gamma = $args[0];	// :TODO: seems OK but not entirely sure I've replicated this properly
				}
				$args[1] = $gamma;
				$args[0] = 0;
			} else if ($id == 5) {		// colorize. Imagick uses hex string, GD uses ints
				@list($r, $g, $b, $a) = $args;
				$args = array(
					'#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) . str_pad(dechex($g), 2, '0', STR_PAD_LEFT) . str_pad(dechex($b), 2, '0', STR_PAD_LEFT),
					$a / 128,
				);
			}
		}

		$baseArgs = $args;

		// read single or mutliple filters & args to apply, and execute all
		if (is_array(self::$IMAGE_FILTER_IDS[$id][0])) {
			foreach (self::$IMAGE_FILTER_IDS[$id] as $callback) {
				// merge args with defaults
				foreach ($callback[1] as $arg => $val) {
					if (!isset($args[$arg])) {
						$args[$arg] = $val;
					}
				}

				// call each filter in turn. only last return value will be passed to caller
				$lastResult = call_user_func_array(array($this, $callback[0]), $args);
				$args = $baseArgs;
			}
		} else {
			// merge aargs with defaults
			foreach (self::$IMAGE_FILTER_IDS[$id][1] as $arg => $val) {
				if (!isset($args[$arg])) {
					$args[$arg] = $val;
				}
			}

			// run single filter
			$lastResult = call_user_func_array(array($this, self::$IMAGE_FILTER_IDS[$id][0]), $args);
		}

		return $lastResult;
	}

	//--------------------------------------------------------------------------

	public static function getAllowableMethods()
	{
		return self::$IMAGE_FILTER_PARAMS;
	}
}
