<?php
/**
 * Variable & config loader for external HTTP requests
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-11-16
 */

abstract class ImthumbRequestHandler
{
	public static $DOCROOT;

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
			'externalAllowed' => !self::readConst('BLOCK_EXTERNAL_LEECHERS', false),
			'rateLimiter' => self::readConst('IMTHUMB_RATE_LIMITER', false),

			'baseDir' => self::readConst('IMTHUMB_BASEDIR', self::readConst('LOCAL_FILE_BASE_DIRECTORY')),	// :NOTE: IMTHUMB_BASEDIR maintains compatibility with early versions of ImThumb

			'cache' => self::readConst('FILE_CACHE_ENABLED', true) ? self::readConst('FILE_CACHE_DIRECTORY', './cache') : false,
			'cachePrefix' => self::readConst('FILE_CACHE_PREFIX', 'timthumb'),
			'cacheSuffix' => self::readConst('FILE_CACHE_SUFFIX', '.timthumb.txt'),
			'cacheMaxAge' => self::readConst('FILE_CACHE_MAX_FILE_AGE', 86400),
			'cacheCleanPeriod' => self::readConst('FILE_CACHE_TIME_BETWEEN_CLEANS', 86400),
			'cacheSalt' => self::readConst('FILE_CACHE_NAME_SALT', 'IOLUJN!(Y&)(TEHlsio(&*Y3978fgsdBBu'),
			'browserCache' => !self::readConst('BROWSER_CACHE_DISABLE', false),
			'browserCacheMaxAge' => self::readConst('BROWSER_CACHE_MAX_AGE', 86400),

			'silent' => self::readConst('SKIP_IMTHUMB_HEADERS', false),	// by default we send generator and timing stats in response headers
			'debug' => self::readConst('SHOW_DEBUG_STATS', false),		// show timing and resource usage statistics in HTTP headers
		);

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

			// check for rate limiting
			if (!$handler->checkRateLimits()) {
				$msg = self::readConst('IMTHUMB_RATE_LIMIT_MSG', "Rate limit exceeded. Please do not hammer this script!");
				echo $msg;
				throw new ImThumbCriticalException($msg, ImThumb::ERR_RATE_EXCEEDED);
			}

			// load up the image
			$handler->loadImage();

			// check for browser cache
			if ($handler->hasBrowserCache()) {
				$handler->sendHeaders();
				exit(0);
			}

			// check and write to caches
			if (!$handler->writeToCache()) {
				// check cache directory for expired content if we processed an already cached image
				$handler->checkExpiredCaches();
			}

			// output the image and all headers
			if (!$handler->display()) {
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
			$handler->sendHeaders($msg);
			echo $theImage;
			exit(0);
		}
	}

	protected static function readParam($name, $default = null)
	{
		return isset($_GET[$name]) ? $_GET[$name] : $default;
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
}
