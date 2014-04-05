<?php
/**
 * Loader interface - responsible for reading in remote assets.
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2014-04-03
 */

interface ImThumbLoader
{
	public function retrieve($uri);
}
