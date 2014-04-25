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
		$meta = new ImThumbMeta();

		$src = $this->getRealImagePath($src, $requestor->param('baseDir'));

		$meta->mtime = @filemtime($src);
		if (false === $meta->mtime) {
			return $meta->invalid();
		}

		$meta->fileSize = @filesize($src);

		$sData = @getimagesize($src);	// ensure it's an image
		if (!$sData) {
			return $meta->invalid();
		}

		$meta->mimeType = strtolower($sData['mime']);
		if(!preg_match('/^image\//i', $meta->mimeType)) {
			$meta->mimeType = 'image/' . $meta->mimeType;
		}
		if ($meta->mimeType == 'image/jpg') {
			$meta->mimeType = 'image/jpeg';
		}

		$meta->src = $src;

		return $meta;
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
