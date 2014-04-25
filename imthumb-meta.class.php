<?php
/**
 * A metadata container class to pass between ImThumb instances and ImThumbSource implementations
 */

class ImThumbMeta
{
	public $src;		// must be a fully resolvable URI
	public $valid = true;

	public $mtime;
	public $fileSize;
	public $mimeType = '';

	public function __construct($src = null, $mtime = null, $fileSize = null, $mimeType = '')
	{
		$this->src = $src;
		$this->mtime = $mtime;
		$this->fileSize = $fileSize;
		$this->mimeType = $mimeType;

		if (!$src) {
			$this->valid = false;
		}
	}

	public function validateWith(ImThumb $generator)
	{
		if (!$this->valid) {
			throw new ImThumbNotFoundException("Could not read image metadata", ImThumb::ERR_SRC_IMAGE);
		}
		if ($this->fileSize > $generator->param('maxSize')) {
			throw new ImThumbException("Image file exceeds maximum processable size", ImThumb::ERR_SRC_IMAGE);
		}
	}

	public function assignMimeType(Imagick $target)
	{
		if ($this->mimeType == 'image/jpeg') {
			$target->setImageFormat('jpg');
		} else if ($this->mimeType == 'image/gif') {
			$target->setImageFormat('gif');
		} else if ($this->mimeType == 'image/png') {
			$target->setImageFormat('png');
		}
	}

	// Helper for invaliding metadata when being read by Source classes. Just return this method call.
	public function invalid()
	{
		$this->valid = false;
		$this->mtime = 0;
		$this->fileSize = 0;
		$this->mimeType = '';

		return $this;
	}
}
