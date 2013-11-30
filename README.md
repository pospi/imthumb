## ImThumb

> A [TimThumb](http://www.binarymoon.co.uk/projects/timthumb/) compatible image generation script, based on ImageMagick instead of GD.

### About

I originally wrote this because I was attempting to optimise the quality of TimThumb generated images, and realised that the lower quality images actually had larger filesizes than the higher quality ones. I ended up digging in a bit and came to the conclusion that some of GD's internals, as well as the way TimThumb uses it, were responsible. Rather than attempt to patch a very old and neglected library and fight against GD, I decided to reimplement the same functionality on top of ImageMagick.

* Drop-in replacement, uses the same configuration files as TimThumb does.
* Self-contained class files and structured image handling API for direct integration with other applications and scripts.

#### Additional Functionality

The following additional features are provided by ImThumb in addition to baseline TimThumb functionality:

* Direct cropping using explicit coordinates. The querystring parameter `cr` accepts four comma-delimited numbers indicating pixel positions to crop to: `startX`, `startY`, `endX` and `endY`. If any numbers are negative or outside the bounds of the image then the image will be positioned within a larger canvas containing itself. When combined with the normal `w`idth and `h`eight parameters, the resulting cropped image is reprocessed to fit within the target dimensions as it would normally be when passed to ImThumb pre-cropped.
* Extended image filters from the ImageMagick library. For a full list, see [this manpage](http://www.php.net/manual/en/class.imagick.php#imagick.imagick.methods) under the heading "*Image effects*".
* Progressive JPEG encoding for better image load experience. Can be disabled by passing `p=0` to the script.
* Ability to specify image cache filenames based on attributes of the image. This allows generation of image files directly usable by external services and applications.
* Support for custom rate limiting. Full details are detailed [below](#implementing-rate-limiting).

#### Improved Functionality

* Better compression of generated images and smaller filesizes.
* Filters work correctly with respect to transparent images, and do not leave filtered areas outside of explicity transparent pixels
* GIF images can be resized whilst retaining their transparent backgrounds.
* Error handling is both more useful & unobtrusive, with meaningful error messages returned as extended HTTP headers and non-configured 404 & error images displayed as coloured squares.

#### TimThumb Incompatibilities

The following features work differently or are otherwise incompatible with TimThumb. You might want to stick to it if you depend on any of these things:

* Progressive JPEGs are saved by default unless the parameter `p` is explicitly set to `0`.
* I decided to expose some timing and cache stats as well as an X-Generator header in responses by default as ImageMagick can be a resource-heavy library and these things are good to know. You can disable this behaviour by defining a constant - `define('SKIP_IMTHUMB_HEADERS', true);`.
* Error images are rendered to the exact dimensions of the requested image, rather than being returned as-is.

### Configuration

As with TimThumb, configuration is managed by constants defined in a config file. This file may exist in the same directory as `imthumb.php`, or one above it (to account for the fact that the library contains several files and would be better managed inside its own subfolder). You can find a full listing of the TimThumb configuration constants [in this post](http://www.binarymoon.co.uk/2012/03/timthumb-configs/).

##### Additional constants:

* `DEFAULT_PROGRESSIVE_JPEG` - set to `false` to show standard JPEGs by default, without specifying the query argument each time.
* `SKIP_IMTHUMB_HEADERS` - set to `false` to prevent `X-Generator` and `X-Img-Cache` headers being sent. Setting `SHOW_DEBUG_STATS` to `true` will enable output of timing and load stats in extended headers.
* By default, ImThumb will always render an image if an error occurs, even if no fallbacks are configured. Due to the difference, some additional constants may be used:
	* `ENABLE_NOT_FOUND_IMAGE` and `ENABLE_ERROR_IMAGE` can be set to `false` to restore plaintext error output and default TimThumb functionality. Broken images will appear as broken X's on your pages.
	* `COLOR_NOT_FOUND_IMAGE` and `COLOR_ERROR_IMAGE` are used to control the colours displayed for the two, err, fallback fallback images.
* `FILE_CACHE_FILENAME_FORMAT` - use this option to specify a format string for writing files to the cache. This can be used for example to permanently generate images for use by other services or in backups. The sprintf format it takes can include any of the following: `%filename%-%w%x%h%x%q%-%zc%%a%%s%%cc%%ct%%filters%%pjpg%.%ext%`, but you need not include all parameters if you will not be using them all.
* `IMTHUMB_RATE_LIMITER` - defines the name of a loaded class to be instantiated for controlling [rate limiting](#implementing-rate-limiting).

##### Unimplemented constants:

* TimThumb's `DEBUG_ON` and `DEBUG_LEVEL` constants have no effect in ImThumb. The library is designed to use exception handling to handle error conditions and log automatically.

### Usage

ImThumb takes the same parameters as TimThumb for the most part - these control simplified cropping & quality settings as well as some basic filters. You can find the full list with examples on the [TimThumb documentation page](http://www.binarymoon.co.uk/2012/02/complete-timthumb-parameters-guide/).

#### Implementing Rate Limiting

To provide simple integration for any custom rate limiting system, the `ImThumbRateLimiter` class provided allows you to integrate your own logic for managing limits on image generation. The only method you need implement is `checkRateLimits()`, which returns `true` to indicate that the generation may proceed and `false` if the rate limit has been exceeded. `ImThumbRateLimiter` objects provide a `$generator` member for interrogating the `ImThumb` instance being processed. The new `IMTHUMB_RATE_LIMITER` configuration constant corresponds to the pre-included classname which will be initialised to control rate limiting on the script. I have also provided `IMTHUMB_RATE_LIMIT_MSG` to control the error HTML that a user sees after exceeding rate limits.

Of course to use this functionality you will need to create a separate entrypoint script that loads up your custom rate limiter class and then boots up `imthumb.php`. You do not need to worry about making `imthumb.php` web inaccessible as it is programmed to die (and log the remote IP to the PHP error log) if it has been given configuration for a rate limiter class that does not exist. These scripts will essentially be a case of including the interface class, defining your own custom class & loading whatever framework is needed to support it, and finally including `imthumb.php` which will load up its configuration file and continue on from there.

If you don't need access to the data from the ImThumb instance to determine your limits, then this framework is purely academic and you can simply create your own script and check for exceeded limits before even loading the main script. Its purpose is more geared toward online services performing batch image generation; and of course demoing the thing without killing your webserver :p

Disclaimer: Note that session-level rate limiting can be circumvented by attackers simply by starting a new session on each request. In order to properly guard against sustained server abuse of this kind one really needs to have something active at a webserver level.

### Notes on Performance

In my testing, ImageMagick typically has a much lower memory usage than GD (about 3x), but higher CPU utilisation (about 2x). Obviously there are advantages and disadvantages to each library and its internals and your choice will depend on your infrastructure and requirements. Imagick offers a much richer set of image manipulation methods at the expense of more intensive computations.

### License and Credits

This software is licensed under an MIT open source license, see LICENSE.txt for details.

&copy; 2013 Sam Pospischil (pospi at spadgos.com)<br />
Initially developed at [Map Creative](http://mapcreative.com.au).

### TODO

* Add support for scaling animated gifs whilst retaining animation
* Extension for processing external images
* Addon for webpage rendering
* Support for ImageMagick 3 and under in 'inner fit' and 'inner fill' crop modes
