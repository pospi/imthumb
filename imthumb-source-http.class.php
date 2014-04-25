<?php
/**
 * Remote image loader
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2014-04-03
 * @depends	ImThumbSource_Local
 */

class ImThumbSource_HTTP implements ImThumbSource
{
	// for size checking remote assets as they stream in
	private static $curlDataWritten;
	private static $curlFH;
	private static $requestor;

	public function readMetadata($src, ImThumb $requestor)
	{
		$localCacheFile = $this->cacheRemoteResource($src, $requestor);
		if (!$localCacheFile) {
			return new ImThumbMeta();	// could not be read, return invalid metadata
		}

		// :SHONK: we update the path in the requestor so that it reads against the local file from here forwards
		$requestor->forceSrc($localCacheFile);

		$localProcessor = new ImThumbSource_Local();
		return $localProcessor->readMetadata($localCacheFile, $requestor);
	}

	public function readResource(ImThumbMeta $meta, ImThumb $requestor)
	{
		if (!$meta->valid) {
			return null;
		}

		$target = new Imagick();
		$target->readImage($meta->src);

		return $target;
	}

	//--------------------------------------------------------------------------

	public function cacheRemoteResource($src, ImThumb $requestor)
	{
		if (!$requestor->cache) {
			throw new ImThumbException('Cannot serve remote images without caching enabled', ImThumb::ERR_CACHE);
		}

		$localCacheFile = $requestor->cache->getDirectory() . '/' . urlencode($src);

		if (!file_exists($localCacheFile)) {
			// :TODO: set a timeout on local caches and reload periodically
			self::$requestor = $requestor;
			if (!($localCacheFile = $this->readUrl($src, $localCacheFile, $requestor))) {
				return null;
			}
		}

		return $localCacheFile;
	}

	protected function readUrl($url, $destFile, ImThumb $requestor)
	{
		$url = preg_replace('/ /', '%20', $url);

		// prefer cURL, or hope that stream wrappers are enabled
		if (function_exists('curl_init')) {
			$this->readUrl_Curl($url, $destFile, $requestor);
		} else {
			$this->readUrl_StreamWrapper($url, $destFile);
		}

		return $destFile;
	}

	// mostly taken from TimThumb
	private function readUrl_Curl($url, $destFile, ImThumb $requestor)
	{
		self::$curlFH = @fopen($destFile, 'w');
		if (!self::$curlFH) {
			throw new ImThumbException('Could not open cache file for remote image', ImThumb::ERR_CACHE);
		}

		self::$curlDataWritten = 0;

		$curl = curl_init($url);
		curl_setopt ($curl, CURLOPT_TIMEOUT, $requestor->param('externalRequestTimeout'));
		curl_setopt ($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/534.30 (KHTML, like Gecko) Chrome/12.0.742.122 Safari/534.30");
		curl_setopt ($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt ($curl, CURLOPT_HEADER, 0);
		curl_setopt ($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt ($curl, CURLOPT_WRITEFUNCTION, 'ImThumbSource_HTTP::curlWrite');
		@curl_setopt ($curl, CURLOPT_FOLLOWLOCATION, true);
		@curl_setopt ($curl, CURLOPT_MAXREDIRS, 10);

		$curlResult = curl_exec($curl);
		$httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		fclose(self::$curlFH);
		curl_close($curl);

		if ($httpStatus == 404) {
			throw new ImThumbNotFoundException('Could not locate remote image', ImThumb::ERR_SRC_IMAGE);
		}
		if ($httpStatus == 302) {
			throw new ImThumbNotFoundException("External Image is Redirecting. Try alternate image url", ImThumb::ERR_SRC_IMAGE);
		}
		if (!$curlResult) {
			throw new ImThumbException("Internal cURL error fetching remote file", ImThumb::ERR_SRC_IMAGE);
		}
	}

	// mostly taken from TimThumb
	private function readUrl_StreamWrapper($url, $destFile)
	{
		$img = file_get_contents($url);
		if ($img === false) {	// some fun oldPHP for determining a 404 error in the stream wrapper
			$err = error_get_last();
			if (is_array($err) && $err['message']) {
				$err = $err['message'];
			}
			if (preg_match('/404/', $err)) {
				throw new ImThumbNotFoundException('Could not locate remote image', ImThumb::ERR_SRC_IMAGE);
			}

			return false;
		}
		if (!@file_put_contents($destFile, $img)) {
			throw new ImThumbException('Could not open cache file for remote image', ImThumb::ERR_CACHE);
		}
	}

	// taken straight from TimThumb, allows streaming of remote data
	public static function curlWrite($h, $d)
	{
		fwrite(self::$curlFH, $d);
		self::$curlDataWritten += strlen($d);
		if (self::$curlDataWritten > self::$requestor->param('maxSize')) {
			return 0;
		} else {
			return strlen($d);
		}
	}
}
