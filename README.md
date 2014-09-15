## ImThumb

> A [TimThumb](http://www.binarymoon.co.uk/projects/timthumb/) compatible image generation script, based on ImageMagick instead of GD.




### About

I originally wrote this because I was attempting to optimise the quality of TimThumb generated images, and realised that the lower quality images actually had larger filesizes than the higher quality ones. I ended up digging in a bit and came to the conclusion that some of GD's internals, as well as the way TimThumb uses it, were responsible. Rather than attempt to patch a very old and neglected library and fight against GD, I decided to reimplement the same functionality on top of ImageMagick.

* Drop-in replacement, uses the same configuration files as TimThumb does.
* Self-contained class files and structured image handling API for direct integration with other applications and scripts.

#### ***Version history***

- `1.0` - baseline functionality. Handles a complete set of TimThumb parameters and operations when running on local files, with some neat additions. In production use.
- `2.x` (current) - massive refactoring, more modular class structure. The new image reading API allows drawing in remote images via HTTP or indeed any concievable data source. **Alpha** and in progress.



#### Additional Functionality

The following additional features are provided by ImThumb in addition to baseline TimThumb functionality:

* Direct cropping using explicit coordinates.
* Extended image filters from the ImageMagick library. For a full list, see [this manpage](http://www.php.net/manual/en/class.imagick.php#imagick.imagick.methods) under the heading "*Image effects*".
* Support for drawing remote images from a variety of sources, [completely extensible](#custom-image-sources) to add your own custom sources (FTP, Amazon S3, databases etc). Included out of the box are:
	* Remote HTTP images (of course)
	* YouTube (provides a simple wrapper around the HTTP source to automatically pull the preview image for the video URL given)
	* Rendering of [remote webpages](#configuring-webpage-rendering) (via integration with [phantomjs](http://http://phantomjs.org))
* Support for [custom rate limiting](#implementing-rate-limiting).
* Progressive JPEG encoding for better image load experience. Can be disabled by passing `p=0` to the script.
* A new URL flag and configuration constant to disable upscaling of source images.
* Ability to specify image cache filenames based on attributes of the image. This allows generation of image files directly usable by external services and applications.



#### Improved Functionality

* Better compression of generated images and smaller filesizes.
* Filters work correctly with respect to transparent images, and do not leave filtered areas outside of explicity transparent pixels
* GIF images can be resized whilst retaining their transparent backgrounds.
* Error handling is both more useful & unobtrusive, with error and 404 image output and meaningful error messages returned as extended HTTP headers. Non-configured 404 & error images are displayed as coloured squares.



#### TimThumb Incompatibilities

The following features work differently or are otherwise incompatible with TimThumb. You might want to stick to it if you depend on any of these things:

* Progressive JPEGs are saved by default unless reconfigured or the parameter `p` is explicitly set to `0`.
* I decided to expose some timing and cache stats as well as an X-Generator header in responses by default as ImageMagick can be a resource-heavy library and these things are good to know. You can disable this behaviour by defining a constant - `define('SKIP_IMTHUMB_HEADERS', true);`.
* Error images are rendered to the exact dimensions of the requested image, rather than being returned as-is.
* Filter related:
	* Brightness & contrast filters are a little more severe with the Imagick methods currently used than they were with TimThumb.
	* The tint filter seems ramped exponentially rather than linearly, and so is less extreme at lower colour values.
	* Edge outlining renders on a black background, rather than the grey provided by TimThumb. Haven't found an appropriate replacement method yet.
	* When reapplying the gaussian blur filter as in the [TimThumb examples](http://www.binarymoon.co.uk/2010/08/timthumb-image-filters/), blur increases much quicker than originally. The filter will accept [additional arguments](http://au1.php.net/manual/en/imagick.gaussianblurimage.php) in the case of ImThumb however, to control its intensity. The same applies to the selective blur algorithm used.
	* Since filter 10 is documented as a sketch filter, I opted to use a stroked sketch effect to render the image.
* By default, I have disabled `ALLOW_EXTERNAL` entirely as I believe this provides a safer option than allowing site visitors unfettered access to public photo sharing sites via your own webserver.
* ImThumb integrates with the [phantomjs](http://http://phantomjs.org) library rather than [CutyCapt](http://cutycapt.sourceforge.net) as provided by TimThumb. Being a more modern implementation, it is able to process JavaScript and other complex rendering logic faster and for more accurate results. As a result all previous configuration parameters related to this aspect have been replaced with new options. This is not to say that CutyCapt could not be easily implemented as a source handler in future.






### Configuration

As with TimThumb, configuration is managed by constants defined in a config file. This file may exist in the same directory as `imthumb.php`, or one above it (to account for the fact that the library contains several files and would be better managed inside its own subfolder). You can find a full listing of the TimThumb configuration constants [in this post](http://www.binarymoon.co.uk/2012/03/timthumb-configs/).



##### Additional constants:

* `DEFAULT_PROGRESSIVE_JPEG` - set to `false` to show standard JPEGs by default, without specifying the query argument each time.
* `DISABLE_UPSCALING` - set to `true` to disable image upscaling by default.
* `SKIP_IMTHUMB_HEADERS` - set to `false` to prevent `X-Generator` and `X-Img-Cache` headers being sent. Setting `SHOW_DEBUG_STATS` to `true` will enable output of timing and load stats in extended headers.
* By default, ImThumb will always render an image if an error occurs, even if no fallbacks are configured. Due to the difference, some additional constants may be used:
	* `ENABLE_NOT_FOUND_IMAGE` and `ENABLE_ERROR_IMAGE` can be set to `false` to restore plaintext error output and default TimThumb functionality. Broken images will appear as broken X's on your pages.
	* `COLOR_NOT_FOUND_IMAGE` and `COLOR_ERROR_IMAGE` are used to control the colours displayed for the two, err, fallback fallback images.
* `FILE_CACHE_FILENAME_FORMAT` - use this option to specify a format string for writing files to the cache. This can be used for example to permanently generate images for use by other services or in backups. The wildcard format it takes can include any of the following: `%filename%-%w%x%h%x%q%-%zc%%a%%s%%cc%%ct%%filters%%pjpg%.%ext%`, but you need not include all parameters if you will not be using them all.
* `IMTHUMB_RATE_LIMITER` - defines the name of a loaded class to be instantiated for controlling [rate limiting](#implementing-rate-limiting).
* `EXTRA_SOURCE_HANDLERS_PATH` - allows you to define an extra include path for image source loader classes. This gives advanced users the ability to pull images directly out of a database, CDN storage bucket or whatever other implementation you could think of.

##### Configuration globals:

You may optionally define these global PHP variables prior to including the `imthumb.php` script. Note that you can also pass these parameters directly to ImThumb if you do not wish to invoke the TimThumb processing behaviour.

- `$ALLOWED_SITES` - a TimThumb configuration parameter. Specify an array of hostnames to whitelist. Subdomains are allowed as well.
- `$IMAGE_SOURCE_HANDLERS` - an alternative to the above. Allows specifying an array of regexes to match passed paths on, mapping them to `ImThumbSource` handler classes. By way of example, translating the `$ALLOWED_SITES` array into ImThumb's source handlers parameter looks like this:

~~~
	foreach ($ALLOWED_SITES as $site) {
		$uriWhitelist['@^https?://(\w|\.)*?\.?' . str_replace('.', '\\.', $site) . '@'] = 'ImThumbSource_HTTP';
	}
~~~

...you get the idea.

##### Unimplemented constants:

* TimThumb's `DEBUG_ON` and `DEBUG_LEVEL` constants have no effect in ImThumb. The library is designed to use exception handling to handle error conditions and log automatically.

##### Setting up URL rewrites:

One of the issues with serving images from scripts is the filename the browser will choose when saving them. As such you'll generally want to configure your webserver to make the image paths appear 'real' to the outside word so that you're not saving down `imthumb.php.jpg` every single time. The following rewrite patterns will accomplish that easily, assuming ImThumb is stored in `lib/imthumb`:

- For Apache: `RewriteRule  ^images/(.*)$  lib/imthumb/imthumb.php?src=$1	[QSA,L]`
- For nginx: `rewrite  ^/images/(.*)$  lib/imthumb/imthumb.php?src=$1&$args last;`

You could easily extend these matches to include dimensions or other distinguishing information in the filenames if need be, that is left as an exercise to the reader.





### Usage

Simply upload or clone ImThumb onto your server, create a `cache` directory in the same directory you unpack to and ensure the webserver has write access to it. If necessary you can also include a TimThumb compatible [configuration file](http://www.binarymoon.co.uk/2012/03/timthumb-configs/) in the same directory (see additional ImThumb constants above). Your filesystem should look something like this:

~~~
	[somedir]/
		0775 cache/
		0644 imthumb/
		0644 timthumb-config.php
~~~

#### Parameters:

ImThumb takes the same parameters as TimThumb for the most part - these control simplified cropping & quality settings as well as some basic filters. You can find the full list with examples on the [TimThumb documentation page](http://www.binarymoon.co.uk/2012/02/complete-timthumb-parameters-guide/).

#### Additional parameters:

###### Crop rect (`cr`)

Crop rect coordinates for pre-cropping a portion of the source image. Accepts four comma-delimited numbers indicating pixel positions to crop to: `startX`, `startY`, `endX` and `endY`. If any numbers are negative or outside the bounds of the image then the image will be positioned within a larger canvas containing itself. When combined with the normal `w`idth and `h`eight parameters, the resulting cropped image is reprocessed to fit within the target dimensions as it would normally be when passed to ImThumb pre-cropped.

###### Progressive JPEGS (`p`)

Specifies whether (`=1`) or not (`=0`) to save progressive JPEGs. ImThumb saves progressive images by default to increase apparent loading time for the end user. Filesize is not significantly affected either way.

###### Upscaling control (`up`)

Specifies whether images should be upscaled when passed dimensions are larger than the original image size. By default this is enabled, but can be disabled globally with the `DISABLE_UPSCALING` configuration constant. When using a manual crop step (`cr`), this parameter applies to the scaling applied after the initial crop is applied.

#### Extended parameters:

###### Filters (`f`)

Image filters are significantly enhanced in ImThumb and provide the full spectrum of ImageMagick filters. Not only the integer-based filters of TimThumb are possible, but a much wider range of method-based filters as well. See [this manpage](http://www.php.net/manual/en/class.imagick.php#imagick.imagick.methods) under the heading "*Image effects*" for complete documentation.

Filters are combined in the same way as with TimThumb, so commas delineate parameters and the pipe character indicates the next filter. For example, a more advanced form of the standard image negation filter `f=2` can be provided to ImThumb as `f=modulateImage,100,0,100`. Tweaking those numbers can adjust the effects of the filter to create different results. Combing filters is easily achieved like so: `modulateImage,50,50,50|waveImage,10,20`.




#### Custom Image Sources

Advanced users may wish to source images from other locations. ImThumb provides the `ImThumbSource` interface for this purpose, which you may extend and map to ImThumb by matching against source image URIs. In practise you will mostly wish to extend from the `ImThumbSource_Local` implementation however, since the objective of most image sourcers is typically to bring down a remote asset and cache it before processing locally.

This interface requires two methods to be implemented - `readMetadata` and `readResource`. The former should query basic attributes about the image and return them as a completed `ImThumbMeta` object, or  throw an `ImThumbNotFoundException` if the asset cannot be located. You may also return an invalid `ImThumbMeta` (either one created with an empty constructor or reset with `->invalid()`) to indicate that there was an unresolvable error when retrieving the resource. `readResource` should process a file against some given metadata and return an `Imagick` instance for processing by the framework.




#### Implementing Rate Limiting

To provide simple integration for any custom rate limiting system, the `ImThumbRateLimiter` class provided allows you to integrate your own logic for managing limits on image generation. The only method you need implement is `checkRateLimits()`, which returns `true` to indicate that the generation may proceed and `false` if the rate limit has been exceeded. `ImThumbRateLimiter` objects provide a `$generator` member for interrogating the `ImThumb` instance being processed. The new `IMTHUMB_RATE_LIMITER` configuration constant corresponds to the pre-included classname which will be initialised to control rate limiting on the script. I have also provided `IMTHUMB_RATE_LIMIT_MSG` to control the error HTML that a user sees after exceeding rate limits.

Of course to use this functionality you will need to create a separate entrypoint script that loads up your custom rate limiter class and then boots up `imthumb.php`. You do not need to worry about making `imthumb.php` web inaccessible as it is programmed to die (and log the remote IP to the PHP error log) if it has been given configuration for a rate limiter class that does not exist. These scripts will essentially be a case of including the interface class, defining your own custom class & loading whatever framework is needed to support it, and finally including `imthumb.php` which will load up its configuration file and continue on from there. There is an example provided at [example/rate-limited-loader.php](example/rate-limited-loader.php).

If you don't need access to the data from the ImThumb instance to determine your limits, then this framework is purely academic and you can simply create your own script and check for exceeded limits before even loading the main script. Its purpose is more geared toward online services performing batch image generation or weighting filters based on their relative complexity; and of course demoing the thing without killing your webserver :p

Disclaimer: Note that session-level rate limiting can be circumvented by attackers simply by starting a new session on each request. In order to properly guard against sustained server abuse of this kind one really needs to have something active at a webserver level.



#### Configuring Webpage Rendering

*Coming soon...*





### Notes on Performance

In my testing, ImageMagick typically has a much lower memory usage than GD (about 3x), but higher CPU utilisation (about 2x). Obviously there are advantages and disadvantages to each library and its internals and your choice will depend on your infrastructure and requirements. Imagick offers a much richer set of image manipulation methods at the expense of more intensive computations.





### License and Credits

This software is licensed under an MIT open source license, see LICENSE.txt for details.

&copy; 2013-2014 Sam Pospischil (pospi at spadgos.com)<br />
Initially developed at [Map Creative](http://theweekendedition.com.au).




### TODO

* phantomjs source handler
	* may need to split up source & destination mime type?
* Add support for scaling animated gifs whilst retaining animation
* Support for ImageMagick 3 and under in 'inner fit' and 'inner fill' crop modes
* Fix filter compatibility on some mismatched filters
