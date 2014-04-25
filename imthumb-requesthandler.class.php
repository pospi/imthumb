<?php
/**
 * Variable & config loader for external HTTP requests
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-11-16
 */

abstract class ImThumbRequestHandler
{
	public static $DOCROOT;
	private static $serverHost;

	/**
	 * Read input request parameters and configuration variables / constants from TimThumb configuration files.
	 */
	public static function readParams()
	{
		global $ALLOWED_SITES, $IMAGE_SOURCE_HANDLERS;

		$allowExternalHTTP = self::readConst('ALLOW_EXTERNAL', false);
		$allowAllHTTP = self::readConst('ALLOW_ALL_EXTERNAL_SITES', false);

		// image source handlers: process global TimThumb variables and convert to our regex-based format for URL matching
		if ($IMAGE_SOURCE_HANDLERS) {
			$uriWhitelist = $IMAGE_SOURCE_HANDLERS;
		} else {
			$uriWhitelist = array();
		}
		if ($allowAllHTTP) {
			$uriWhitelist['@^https?://@'] = 'ImThumbSource_HTTP';
		} else if ($allowExternalHTTP) {
			foreach ($ALLOWED_SITES as $site) {
				$uriWhitelist['@^https?://(\w|\.)*?\.?' . str_replace('.', '\\.', $site) . '@'] = 'ImThumbSource_HTTP';
			}
		}

		// build params for the class
		return array(
			'src' => self::readParam('src'),
			'width' => self::readParam('w', null),
			'height' => self::readParam('h', null),
			'quality' => self::readParam('q', self::readConst('DEFAULT_Q', 90)),
			'align' => self::readParam('a', 'c'),
			'cropMode' => self::readParam('zc', self::readConst('DEFAULT_ZC', 1)),
			'sharpen' => self::readParam('s', self::readConst('DEFAULT_S', 0)),
			'canvasColor' => self::readParam('cc', self::readConst('DEFAULT_CC', 'ffffff')),
			'canvasTransparent' => (bool)self::readParam('ct', true),

			'cropRect' => self::readParam('cr', null),

			'filters' => self::readParam('f', self::readConst('DEFAULT_F', '')),

			'fallbackImg' => self::readConst('ENABLE_NOT_FOUND_IMAGE', true) ? self::readConst('NOT_FOUND_IMAGE', true) : false,
			'errorImg' => self::readConst('ENABLE_ERROR_IMAGE', true) ? self::readConst('ERROR_IMAGE', true) : false,
			'fallbackColor' => self::readConst('COLOR_NOT_FOUND_IMAGE', '#FF7700'),
			'errorColor' => self::readConst('COLOR_ERROR_IMAGE', '#FF0000'),

			'pngTransparency' => self::readConst('PNG_IS_TRANSPARENT', true),
			'jpgProgressive' => self::readParam('p', self::readConst('DEFAULT_PROGRESSIVE_JPEG', 1)),

			'maxw' => self::readConst('MAX_WIDTH', 1500),
			'maxh' => self::readConst('MAX_HEIGHT', 1500),

			'maxSize' => self::readConst('MAX_FILE_SIZE', 10485760),
			'memoryLimit' => self::readConst('MEMORY_LIMIT', false),
			'externalReferrerAllowed' => !self::readConst('BLOCK_EXTERNAL_LEECHERS', false),
			'rateLimiter' => self::readConst('IMTHUMB_RATE_LIMITER', false),
			'rateExceededMessage' => self::readConst('IMTHUMB_RATE_LIMIT_MSG', "Rate limit exceeded. Please do not hammer this script!"),

			'baseDir' => self::readConst('IMTHUMB_BASEDIR', self::readConst('LOCAL_FILE_BASE_DIRECTORY')),	// :NOTE: IMTHUMB_BASEDIR maintains compatibility with early versions of ImThumb

			'cache' => self::readConst('FILE_CACHE_ENABLED', true) ? self::readConst('FILE_CACHE_DIRECTORY', './cache') : false,
			'cachePrefix' => self::readConst('FILE_CACHE_PREFIX', 'timthumb'),
			'cacheSuffix' => self::readConst('FILE_CACHE_SUFFIX', '.timthumb.txt'),
			'cacheFilenameFormat' => self::readConst('FILE_CACHE_FILENAME_FORMAT', false),
			'cacheMaxAge' => self::readConst('FILE_CACHE_MAX_FILE_AGE', 86400),
			'cacheCleanPeriod' => self::readConst('FILE_CACHE_TIME_BETWEEN_CLEANS', 86400),
			'cacheSalt' => self::readConst('FILE_CACHE_NAME_SALT', 'IOLUJN!(Y&)(TEHlsio(&*Y3978fgsdBBu'),
			'browserCache' => !self::readConst('BROWSER_CACHE_DISABLE', false),
			'browserCacheMaxAge' => self::readConst('BROWSER_CACHE_MAX_AGE', 86400),

			'sourceHandlers' => $uriWhitelist,		// remote image source handler URI mappings. Use a global for easy preconfiguration in options like TimThumb.
			'extraSourceHandlerPath' => self::readConst('EXTRA_SOURCE_HANDLERS_PATH', null),	// directory to autoload any custom source handlers from. if you don't know what this means then you don't need this.
			'externalRequestTimeout' => self::readConst('CURL_TIMEOUT', 20),				// Timeout duration for Curl. This only applies if you have Curl installed and aren't using PHP's default URL fetching mechanism.
			'externalRequestRetry' => self::readConst('WAIT_BETWEEN_FETCH_ERRORS', 3600),	// Time to wait between errors fetching remote file

			'webshot' => self::readParam('webshot', 0),		// if true, requesting a remote webpage and want to render it to an image

			'silent' => self::readConst('SKIP_IMTHUMB_HEADERS', false),	// by default we send generator and timing stats in response headers
			'debug' => self::readConst('SHOW_DEBUG_STATS', false),		// show timing and resource usage statistics in HTTP headers
		);
	}

	public static function processRequest(Array $params)
	{
		// set timezone if unset to avoid warnings
		date_default_timezone_set(@date_default_timezone_get());

		// set memory limit if required
		if ($params['memoryLimit']) {
			$inibytes = self::returnBytes(ini_get('memory_limit'));
			$ourbytes = self::returnBytes($params['memoryLimit']);
			if ($inibytes < $ourbytes) {
				@ini_set('memory_limit', $params['memoryLimit']);
			}
		}

		try {
			// load up the configured rate limiter class, if any
			if (!empty($params['rateLimiter'])) {
				$limiter = $params['rateLimiter'];

				// if the configured class does not exist, this is a hack attempt and someone is hitting the imthumb.php script directly to bypass rate limiting
				if (!class_exists($limiter)) {
					$remoteIPString = isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : (
						isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')
					);
					throw new ImThumbCriticalException("Rate limiter class not found, hack attempt! Source {$remoteIPString}", ImThumb::ERR_HACK_ATTEMPT);
				}

				$params['rateLimiter'] = new $limiter();
			}
			$handler = new ImThumb($params);
			$http = new ImThumbHTTP($handler, $params);

			// check for referrer
			if (!$params['externalReferrerAllowed'] && !self::isReferrerOk()) {
				self::quickImageResponse('leeching');
			}

			// check for rate limiting
			if (!$handler->checkRateLimits()) {
				self::quickImageResponse('ratelimited');
			}

			// load up the image
			$handler->loadImage($params['src']);

			// check for browser cache
			if ($handler->hasBrowserCache()) {
				$http->sendHeaders();
				exit(0);
			}

			// check and write to caches
			if (!$handler->writeCache()) {
				// check cache directory for expired content if we processed an already cached image
				if ($handler->cache) {
					$handler->cache->checkExpiredCaches();
				}
			}

			// output the image and all headers
			if (!$http->display()) {
				// nothing to display, we aren't configured to show any fallback 404 image
				throw new ImThumbCriticalException("Source image not found. Source querystring: {$_SERVER['QUERY_STRING']}", ImThumb::ERR_SERVER_CONFIG);
			}
		} catch (Exception $e) {
			if ($e instanceof ImThumbCriticalException) {
				throw $e;
			}

			// attempt to load any configured error image. If this errors out it will just throw the exception naturally.
			$handler->loadErrorImage();
			$handler->configureUnalteredFitImage();
			$handler->doResize();

			if ($e instanceof ImThumbException) {
				if ($e->getCode() == ImThumb::ERR_SRC_IMAGE) {
					// log the querystring passed in addition to regular message
					$msg = $e->getMessage() . ' Source querystring: ' . $_SERVER['QUERY_STRING'];
				} else {
					$msg = $e->getMessage();
				}
			} else {
				$msg = 'Unknown error ' . $e->getCode() . ': ' . $e->getMessage();
			}

			$theImage = $handler->getImage();
			if (!$theImage) {
				throw $e;
			}
			$http->sendHeaders($msg);
			echo $theImage;
			exit(0);
		}
	}

	protected static function readParam($name, $default = null)
	{
		return (isset($_GET[$name]) && $_GET[$name] != '') ? $_GET[$name] : $default;
	}

	protected static function readConst($name, $default = null)
	{
		return defined($name) ? constant($name) : $default;
	}

	// from TimThumb
	protected static function returnBytes($size_str)
	{
		switch (substr ($size_str, -1)) {
			case 'M': case 'm': return (int)$size_str * 1048576;
			case 'K': case 'k': return (int)$size_str * 1024;
			case 'G': case 'g': return (int)$size_str * 1073741824;
			default: return $size_str;
		}
	}

	// base64 encoded images with text for potentially thrased responses
	protected static function quickImageResponse($id)
	{
		switch ($id) {
			case 'leeching':
				$imgData = base64_decode("R0lGODlhIwElAOfeAPwPPvwQP/wRQPwSQPwTQfwUQvwVQ/wWQ/wXRPwXRfwYRvwZRvwaR/wbSPwcSfwdSfweSvwfS/wgTPwhTPwiTfwjTvwkT/wlT/wmUPwnUfwnUvwoUvwpU/wqVPwrVfwsVfwtVvwuV/wvWPwwWPwxWfwyWvwzW/w0XPw2Xfw3Xv03X/04X/05YP06Yf07Yv08Yv09Y/0+ZP0/Zf1AZf1BZv1CZ/1DaP1EaP1Faf1Gav1Ha/1IbP1Jbf1Kbv1Lbv1Mb/1NcP1Ocf1Pcf1Qcv1Rc/1SdP1TdP1Udf1Vdv1XeP1Zev1ae/1be/1cfP1dff1efv1ffv1gf/1hgP1igf1jgf1nhP1ohv1ph/1qh/1riP1sif1tiv1uiv1vi/1wjP1xjf1yjf1zjv10j/11kP12kf13kf13kv14k/15lP16lP17lf18lv19l/1/mP2Amf2Bmv2Cmv2Dm/2EnP2Fnf2Gnf2Hnv6Hn/6IoP6JoP6Kof6Lov6Mo/6OpP6Ppf6Qpv6TqP6Uqf6Vqf6Wqv6Xq/6XrP6Yrf6arv6csP6esf6fsv6gs/6jtf6ktv6ltv6nuP6nuf6ouf6puv6qu/6tvf6uvv6vv/6wv/6xwP6ywf62xP63xf65x/66yP67yf68yf69yv6+y/6/zP7Bzf7Dz/7Ez/7F0P7G0f7H0v7I0/7J1P7K1f7L1f7M1v7N1/7O2P7Q2f7R2v7S2/7T2/7V3f7W3v7X3v/X3//Y4P/Z4f/a4f/b4v/c4//d5P/e5f/f5f/h5//i6P/j6P/k6f/l6v/m6//n6//n7P/o7f/p7v/q7v/r7//t8f/u8f/v8v/w8//x9P/y9P/z9f/09v/19//29//3+P/3+f/4+v/5+v/6+//7/P/8/f/9/f/+/v///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////yH5BAEAAP8ALAAAAAAjASUAAAj+AL0JHEiwoMGDCBMqXMiwocOHECECKBKx4sKJFgtizMixo8ePIEOKDLlxpESKHkuaXMmypcuXKVHCTKgyY82ZOHPq3GlTJk+BG2t0iGbx5s+jSJPCNLrS6MYOCZgV9am0qtWr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");
				break;
			case 'ratelimited':
				$imgData = base64_decode("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");
				break;
		}

		header('Content-Type: image/gif');
		header('Content-Length: ' . strlen($imgData));
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header("Pragma: no-cache");
		header('Expires: ' . gmdate('D, d M Y H:i:s', time()));

		echo $imgData;

		exit(0);
	}

	//--------------------------------------------------------------------------

	// attempts to find and load TimThumb config files and set defaults. Looks in CWD and parent dir.
	// @see http://www.binarymoon.co.uk/2012/03/timthumb-configs/
	public static function readTimThumbConfig($basePath = null)
	{
		if ($basePath === null) {
			$basePath = dirname(__FILE__);
		}

		if (file_exists($basePath . '/timthumb-config.php'))	{
			require_once($basePath . '/timthumb-config.php');
			return true;
		}

		$basePath = dirname($basePath);
		if (file_exists($basePath . '/timthumb-config.php'))	{
			require_once($basePath . '/timthumb-config.php');
			return true;
		}

		return false;
	}

	//--------------------------------------------------------------------------

	// mostly taken from TimThumb
	public static function getDocRoot()
	{
		if (isset(self::$DOCROOT)) {
			return self::$DOCROOT;
		}

		$docRoot = @$_SERVER['DOCUMENT_ROOT'];
		if (!isset($docRoot)) {
			if (isset($_SERVER['SCRIPT_FILENAME'])) {
				$docRoot = str_replace('\\', '/', substr($_SERVER['SCRIPT_FILENAME'], 0, 0 - strlen($_SERVER['PHP_SELF'])));
			}
		}
		if (!isset($docRoot)) {
			if (isset($_SERVER['PATH_TRANSLATED'])) {
				$docRoot = str_replace('\\', '/', substr(str_replace('\\\\', '\\', $_SERVER['PATH_TRANSLATED']), 0, 0 - strlen($_SERVER['PHP_SELF'])));
			}
		}
		if ($docRoot && $_SERVER['DOCUMENT_ROOT'] != '/') {
			$docRoot = preg_replace('/\/$/', '', $docRoot);
		}

		$docRoot .= '/';

		self::$DOCROOT = $docRoot;

		return $docRoot;
	}

	public static function isReferrerOk()
	{
		if (!self::$serverHost) {
			self::$serverHost = preg_replace('/^www\./i', '', $_SERVER['HTTP_HOST']);
		}

		if (array_key_exists('HTTP_REFERER', $_SERVER)
		 && !preg_match('/^https?:\/\/(?:www\.)?' . self::$serverHost . '(?:$|\/)/i', $_SERVER['HTTP_REFERER']) ) {
			return false;
		}

		return true;
	}
}
