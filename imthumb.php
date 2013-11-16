<?php
/**
 * ImThumb
 *
 * A (mostly) timthumb-compatible image generation script using ImageMagick instead of GD
 *
 * General compatibility notes:
 * 	- Define the path to your base folder for serving images from with the constant 'IMTHUMB_BASEDIR'. This path should omit the trailing slash.
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

// Load up configuration (use same filename that timthumb does) and set defaults. Looks in CWD and parent dir.
// @see http://www.binarymoon.co.uk/2012/03/timthumb-configs/
if (file_exists(__DIR__ . '/timthumb-config.php'))	{
	require_once('timthumb-config.php');
}
if (file_exists(dirname(__DIR__) . '/timthumb-config.php'))	{
	require_once('../timthumb-config.php');
}

require_once('imthumb-requesthandler.class.php');
require_once('imthumb.class.php');

// run the script!
ImthumbRequestHandler::processRequest();
exit(0);
