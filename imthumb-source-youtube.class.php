<?php
/**
 * Youtube thumbnail renderer wrapper
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2014-04-26
 */

if (!class_exists('ImThumbSource_HTTP')) {
	require_once(dirname(__FILE__) . '/imthumb-source-http.class.php');
}

class ImThumbSource_YouTube extends ImThumbSource_HTTP
{
	public function readMetadata($src, ImThumb $requestor)
	{
		preg_match('/(\?|&)v=(\w+)(\W|$)/', $src, $matches);
		$src = 'http://img.youtube.com/vi/' . $matches[2] . '/0.jpg';

		return parent::readMetadata($src, $requestor);
	}
}
