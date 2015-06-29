<?php
/**
 * Image cache handler
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2014-04-03
 */

class ImThumbCache
{
	private static $defaults = array(
		'cacheCleanPeriod' => 86400,
		'cacheMaxAge' => 86400,

		'cachePrefix' => 'timthumb',
		'cacheSalt' => 'IOLUJN!(Y&)(TEHlsio(&*Y3978fgsdBBu',	/* whatevs this gets overridden by requestHandler mostly */
		'cacheSuffix' => '.timthumb.txt',
		'cacheFilenameFormat' => false,
	);

	public $mtime;

	protected $baseDir;

	protected $cacheCleanPeriod;
	protected $cacheMaxAge;

	protected $cachePrefix;
	protected $cacheSalt;
	protected $cacheSuffix;
	protected $cacheFilenameFormat;

	protected $hasCache = false;

	//----------------

	public function __construct($baseDir, Array $options) {
		$this->baseDir = $baseDir;

		$options = array_merge(self::$defaults, $options);

		// :IMPORTANT: do not ever allow external user input to find its way into these options as it would allow overriding random member variables
		foreach ($options as $k => $v) {
			$this->{$k} = $v;
		}
	}

	public function load($src)
	{
		$this->initCacheDir();

		$this->mtime = $this->getModTime($src);
		$this->hasCache = $this->mtime !== false;
	}

	public function isCached($cache = null)
	{
		if ($cache !== null) {
			$this->hasCache = $cache;
		}

		return $this->hasCache;
	}

	public function getDirectory()
	{
		return $this->baseDir;
	}

	//----------------

	public function initCacheDir()
	{
		$cacheDir = $this->baseDir;
		if (!$cacheDir) {
			return;
		}

		if (!touch($cacheDir . '/index.html')) {
			throw new ImThumbException("Could not create the index.html file - to fix this create an empty file named index.html file in the cache directory.", ImThumb::ERR_CACHE);
		}
	}

	public function write(ImThumb $imageHandle)
	{
		if ($this->hasCache || !$this->baseDir) {
			return false;
		}

		$this->initCacheDir();

		$tempfile = tempnam($this->baseDir, 'imthumb_tmpimg_');

		if (!$imageHandle->getImagick()->writeImage($tempfile)) {
			throw new ImThumbException("Could not write image to temporary file", ImThumb::ERR_CACHE);
		}

		$cacheFile = $this->getCachePath($imageHandle);
		$lockFile = $cacheFile . '.lock';

		$fh = fopen($lockFile, 'w');
		if (!$fh) {
			throw new ImThumbException("Could not open the lockfile for writing an image", ImThumb::ERR_CACHE);
		}

		if (flock($fh, LOCK_EX)) {
			@unlink($cacheFile);
			rename($tempfile, $cacheFile);
			flock($fh, LOCK_UN);
			fclose($fh);
			@unlink($lockFile);
		} else {
			fclose($fh);
			@unlink($lockFile);
			@unlink($tempfile);
			throw new ImThumbException("Could not get a lock for writing", ImThumb::ERR_CACHE);
		}

		return true;
	}

	public function checkExpiredCaches()
	{
		$cacheDir = $this->baseDir;
		$cacheExpiry = $this->cacheCleanPeriod;
		if (!$cacheDir || !$cacheExpiry || $cacheExpiry < 0) {
			return;
		}

		$lastCleanFile = $cacheDir . '/timthumb_cacheLastCleanTime.touch';

		// If this is a new installation we need to create the file
		if (!is_file($lastCleanFile)) {
			if (!touch($lastCleanFile)) {
				throw new ImThumbException("Could not create cache clean timestamp file.", ImThumb::ERR_CACHE);
			}
		}

		// check for last auto-purge time
		if (@filemtime($lastCleanFile) < (time() - $cacheExpiry)) {
			// :NOTE: (from timthumb) Very slight race condition here, but worst case we'll have 2 or 3 servers cleaning the cache simultaneously once a day.
			if (!touch($lastCleanFile)) {
				$this->error("Could not create cache clean timestamp file.");
			}

			$maxAge = $this->cacheMaxAge;
			$files = glob($this->cacheDirectory . '/*' . $this->cacheSuffix);

			if ($files) {
				$timeAgo = time() - $maxAge;
				foreach ($files as $file) {
					if (@filemtime($file) < $timeAgo) {
						@unlink($file);
					}
				}
			}
			return true;
		}

		return false;
	}

	public function getImage(ImThumb $src)
	{
		$path = $this->getCachePath($src);

		if ($path && file_exists($path)) {
			return file_get_contents($path);
		}
		return null;
	}

	//----------------

	protected function getModTime(ImThumb $src)
	{
		return @filemtime($this->getCachePath($src));
	}

	public function getCachePath(ImThumb $imageHandle, $src = null)
	{
		$cacheDir = $this->baseDir;

		if (!$cacheDir) {
			return false;
		}

		if (!$src) {
			$src = $imageHandle->getSrc();
		}

		$extension = substr($src, strrpos($src, '.') + 1);

		if ($this->cacheFilenameFormat) {
			list($width, $height) = $imageHandle->getTargetSize();

			return $cacheDir . '/' . str_replace(array(
				'%filename%',
				'%ext%',
				'%w%',
				'%h%',
				'%q%',
				'%a%',
				'%zc%',
				'%s%',
				'%cc%',
				'%ct%',
				'%cr%',
				'%filters%',
				'%pjpg%',
				'%upscale%',
			), array(
				basename($src, '.' . $extension),
				$extension,
				$width,
				$height,
				$imageHandle->param('quality'),
				$imageHandle->param('align'),
				$imageHandle->param('cropMode'),
				$imageHandle->param('sharpen') ? 's' : '',
				$imageHandle->param('canvasColor'),
				$imageHandle->param('canvasTransparent') ? 't' : '',
				$imageHandle->param('cropRect'),
				$imageHandle->param('filters'),
				$imageHandle->param('jpgProgressive') ? 'p' : '',
				$imageHandle->param('upscale') ? '' : 'nu',
			), $this->cacheFilenameFormat);
		}

		return $cacheDir . '/' . $this->cachePrefix . md5($this->cacheSalt . json_encode($imageHandle->params()) . ImThumb::VERSION) . $this->cacheSuffix;
	}
}
