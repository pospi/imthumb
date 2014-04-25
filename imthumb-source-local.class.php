<?php
/**
 * Default image loader, for local filesystem
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2014-04-24
 */

class ImThumbSource_Local implements ImThumbSource
{
	public function readMetadata($src, ImThumb $requestor)
	{
		$fallbackMeta = new ImThumbMeta();

		$src = $this->getRealImagePath($src, $requestor->param('baseDir'));

		$mtime = @filemtime($src);
		if (false === $mtime) {
			return $fallbackMeta->invalid();
		}

		$fileSize = @filesize($src);

		$sData = @getimagesize($src);	// ensure it's an image
		if (!$sData) {
			return $fallbackMeta->invalid();
		}

		$mimeType = strtolower($sData['mime']);
		if(!preg_match('/^image\//i', $mimeType)) {
			$mimeType = 'image/' . $mimeType;
		}
		if ($mimeType == 'image/jpg') {
			$mimeType = 'image/jpeg';
		}

		return new ImThumbMeta(
			$src,
			$mtime,
			$fileSize,
			$mimeType
		);
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

	protected function getRealImagePath($src, $baseDir = null)
	{
		if ($baseDir) {
			if (preg_match('@^' . preg_quote($baseDir, '@') . '@', $src)) {
				return $src;
			}
			return realpath($baseDir . '/' . $src);
		}

		require_once(dirname(__FILE__) . '/imthumb-requesthandler.class.php');
		return realpath(ImThumbRequestHandler::getDocRoot() . $src);
	}
}
