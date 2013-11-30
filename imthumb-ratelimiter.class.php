<?php
/**
 * Rate limiting interface
 *
 * A blueprint for classes which can control the generation and delivery of images,
 * and a base class implementing some low-level behaviour.
 *
 * @package ImThumb
 * @author  Sam Pospischil <pospi@spadgos.com>
 * @since	2013-10-18
 */

interface IImThumbRateLimiter
{
	/**
	 * @return true if rate is OK, false if exceeded
	 */
	public function checkRateLimits();
}

abstract class ImThumbRateLimiter implements IImThumbRateLimiter
{
	protected $generator;

	/**
	 * Sets the ImThumb instance currently processing in order for child classes
	 * to be able to easily query and manipulate it.
	 *
	 * If your rate limiting does not require access to instance parameters, you can
	 * always omit this step, instantiate your class in your entrypoint script and check it there.
	 * But if that is the case, then really why are you bothering with this interface?
	 *
	 * @param ImThumb $gen ImThumb instance currently being handled for generation
	 */
	public function setGenerator(ImThumb $gen)
	{
		$this->generator = $gen;
	}

	// clean up ref
	public function __destruct()
	{
		unset($this->generator);
	}
}
