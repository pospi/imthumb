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

			'fallbackImg' => self::readConst('NOT_FOUND_IMAGE'),
			'errorImg' => self::readConst('ERROR_IMAGE'),
			'pngTransparency' => self::readConst('PNG_IS_TRANSPARENT', false),

			'jpgProgressive' => self::readParam('p', self::readConst('DEFAULT_PROGRESSIVE_JPEG', 1)),

			'maxw' => self::readConst('MAX_WIDTH', 1500),
			'maxh' => self::readConst('MAX_HEIGHT', 1500),

			'maxSize' => self::readConst('MAX_FILE_SIZE', 10485760),
			'externalAllowed' => !self::readConst('BLOCK_EXTERNAL_LEECHERS', false),

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

		// create image handler
		try {
			$handler = new ImThumb($params);
		} catch (Exception $e) {
			if ($e->getCode() == ImThumb::ERR_SRC_IMAGE) {
				// log the querystring passed in addition to regular message
				throw new Exception($e->getMessage() . ' Source querystring: ' . $_SERVER['QUERY_STRING'], ImThumb::ERR_SRC_IMAGE);
			} else {
				throw $e;
			}
		}

		// load up the image
		$handler->loadImage();

		// check for browser cache
		if ($handler->hasBrowserCache()) {
			$handler->sendHeaders();
			exit(0);
		}

		// check and write to caches
		$handler->writeToCache();

		// output the image and all headers
		if ($handler->display()) {
			// only check caches for clearing after actually serving something, its a slightly expensive operation
			$handler->checkExpiredCaches();
		}
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
