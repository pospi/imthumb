<?php
/**
 * ImThumb loader script with session-backed rate limiting implementation
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-10-18
 */

// Example referring page to limit rate limiting on. Change to your server path if you want to publish
// this page somewhere public but still use the script unlimited elsewhere.
// Comment out if you don't want this check performed.
define('DEMOPAGE_RELATIVE_URI', 'libs/imthumb');

// load the base class first
require_once(dirname(__FILE__) . '/../imthumb-ratelimiter.class.php');

// define rate limiting logic
class ImThumbSessionRateLimiter extends ImThumbRateLimiter
{
	const INTERVAL_LENGTH = 20;			// limit period
	const REQUESTS_PER_INTERVAL = 8;	// number of requests to allow in time window

	public function checkRateLimits()
	{
		// check if on demo page, we will only run the rate limiting there
		if (defined('DEMOPAGE_RELATIVE_URI')) {
			if (empty($_SERVER['HTTP_REFERER'])) {
				return true;
			}
			$referrer = parse_url($_SERVER['HTTP_REFERER']);
			$referrer = rtrim($referrer['path'], '/ ');
			if (strpos($referrer, DEMOPAGE_RELATIVE_URI) === false) {
				return true;
			}
		}

		// begin session
		session_start('ithdemo');

		if (!isset($_SESSION['requestcount'])) {
			$_SESSION['requestcount'] = 0;
		}

		if (!isset($_SESSION['lasttouch'])) {
			// our first request, store starting window time
			$lastTouch = time();
		} else {
			// subsequent request, check to see if we're ready to decrease the counter a bit
			$lastTouch = $_SESSION['lasttouch'];
			if (($lastTouch + self::INTERVAL_LENGTH) < time()) {
				// reset starting window time, decrement request count by 1 window
				$lastTouch = time();
				$_SESSION['requestcount'] -= self::REQUESTS_PER_INTERVAL;
			}
		}

		$_SESSION['lasttouch'] = $lastTouch;

		// check usage and continue incrementing request count
		if (++$_SESSION['requestcount'] > self::REQUESTS_PER_INTERVAL) {
			// set error message on the generator so we can display lockout duration
			$this->generator->param('rateExceededMessage',
				"Rate limit exceeded: {$_SESSION['requestcount']}/" . self::REQUESTS_PER_INTERVAL
				. " requests attempted. Please wait until " . date('Y-m-d H:i:s', $_SESSION['lasttouch'] + (self::INTERVAL_LENGTH * ceil($_SESSION['requestcount'] / self::REQUESTS_PER_INTERVAL)) ));
			return false;
		}

		return true;
	}
}

// process the image!
require_once(dirname(__FILE__) . '/../imthumb.php');
