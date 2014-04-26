<?php
/**
 * ImThumb
 *
 * A (mostly) timthumb-compatible image generation script using ImageMagick instead of GD
 *
 * Installation of ImageMagick can be performed as follows:
 * 	RedHat-based systems:
 * 		$ sudo yum install ImageMagick ImageMagick-devel php-devel php-pear gcc
 * 		$ sudo pecl install imagick
 * 		$ sudo echo extension=imagick.so >> /etc/php.ini
 * 	Ubuntu/Debian-based systems:
 * 		$ sudo apt-get install imagemagick php5-imagick
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-10-18
 */

if (!defined('IMTHUMB_BASE')) {
	define('IMTHUMB_BASE', dirname(__FILE__));
}

require_once('imthumb-requesthandler.class.php');
require_once('imthumb-meta.class.php');
require_once('imthumb-source.class.php');
require_once('imthumb-source-local.class.php');
require_once('imthumb-http.class.php');
require_once('imthumb.class.php');

// look for any TimThumb config files and load them
ImThumbRequestHandler::readTimThumbConfig();

// run the script!
ImThumbRequestHandler::processRequest(ImThumbRequestHandler::readParams());
exit(0);
