<?php
/**
 * HTTP response handler
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2014-04-03
 */

class ImThumbHTTP
{
	private static $defaults = array(
		'allowAllExternal' => false,
		'uriWhitelist' => array(),
		'externalRequestTimeout' => 20,
		'externalRequestRetry' => 3600,
	);

	protected $image;
	protected $params;

	public function __construct(ImThumb $image, Array $params = null)
	{
		$this->image = $image;
		$this->params = array_merge(self::$defaults, isset($params) ? $params : array());
	}

	// Image output
	//--------------------------------------------------------------------------

	public function sendHeaders($serverErrorString = null)
	{
		// check we can send these first
		if (headers_sent()) {
			$this->critical("Could not set image headers, output already started", self::ERR_OUTPUTTING);
		}

		$modifiedDate = gmdate('D, d M Y H:i:s') . ' GMT';

		// get image size. Have to workaround bugs in Imagick that return 0 for size by counting ourselves.
		$byteSize = $this->image->getImageSize();

		if ($this->image->hasBrowserCache()) {
			header('HTTP/1.0 304 Not Modified');
		} else {
			if ($serverErrorString) {
				header('HTTP/1.0 500 Internal Server Error');
				header('X-ImThumb-Error: ' . $serverErrorString);
			} else if (!$this->image->isValid()) {
				header('HTTP/1.0 404 Not Found');
			} else {
				header('HTTP/1.0 200 OK');
			}
			header('Content-Type: ' . $this->image->getMime());
			header('Accept-Ranges: none');
			header('Last-Modified: ' . $modifiedDate);
			header('Content-Length: ' . $byteSize);

			if (!$this->image->param('browserCache')) {
				header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
				header("Pragma: no-cache");
				header('Expires: ' . gmdate('D, d M Y H:i:s', time()));
			} else {
				$maxAge = $this->image->param('browserCacheMaxAge');
				$expiryDate = gmdate('D, d M Y H:i:s', strtotime('now +' . $maxAge . ' seconds')) . ' GMT';
				header('Cache-Control: max-age=' . $maxAge . ', must-revalidate');
				header('Expires: ' . $expiryDate);
			}
		}

		// informational headers
		if (!$this->image->param('silent')) {
			$imVer = Imagick::getVersion();
			header('X-Generator: ImThumb v' . ImThumb::VERSION . '; ' . $imVer['versionString']);

			if ($this->image->cache && $this->image->cache->isCached()) {
				header('X-Img-Cache: HIT');
			} else {
				header('X-Img-Cache: MISS');
			}
			if ($this->image->param('debug')) {
				list($startTime, $startCPU_u, $startCPU_s) = $this->image->getTimingStats();

				header('X-Generated-In: ' . number_format(microtime(true) - $startTime, 6) . 's');
				header('X-Memory-Peak: ' . number_format((memory_get_peak_usage() / 1024), 3, '.', '') . 'KB');

				$data = getrusage();
				$memusage = array((double)($data['ru_utime.tv_sec'] + $data['ru_utime.tv_usec'] / 1000000), (double)($data['ru_stime.tv_sec'] + $data['ru_stime.tv_usec'] / 1000000));
				header('X-CPU-Utilisation:'
					.  ' Usr ' . number_format($memusage[0] - $startCPU_u, 6)
					. ', Sys ' . number_format($memusage[1] - $startCPU_s, 6)
					. '; Base ' . number_format($startCPU_u, 6) . ', ' . number_format($startCPU_s, 6));
			}
		}
	}

	public function display()
	{
		$str = $this->image->getImage();

		if ($str) {
			$this->sendHeaders();
			echo $str;
		}

		return $str ? true : false;
	}

	// :TODO: Webpage rendering
	//--------------------------------------------------------------------------

	//--------------------------------------------------------------------------
	// Error handling

	protected function critical($string, $code = 0)
	{
		throw new ImThumbException($string, $code);
	}
}
