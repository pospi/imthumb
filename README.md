## ImThumb

> A [timthumb](http://www.binarymoon.co.uk/projects/timthumb/) compatible image generation script, based on ImageMagick instead of GD.

### About

I originally wrote this because I was attempting to optimise the quality of timthumb generated images, and realised that the lower quality images actually had larger filesizes than the higher quality ones. I ended up digging in a bit and came to the conclusion that some of GD's internals, as well as the way timthumb uses it, were responsible. Rather than attempt to patch a very old and messy library and fight against GD, I decided to reimplement the same functionality on top of ImageMagick.

### Features

* Drop-in replacement, uses the same configuration files as timthumb does.
* Self-contained class file and accessible image handling API, rather than a messy script designed to run in its own environment.

#### Additional Functionality

The following additional features are provided by ImThumb in addition to baseline timthumb functionality:

* Extended image filters from the ImageMagick library. For a full list, see [this manpage](http://www.php.net/manual/en/class.imagick.php#imagick.imagick.methods) under the heading "*Image effects*".
* Progressive JPEG encoding for better image load experience. Can be disabled by passing `p=0` to the script.
* GIF images can be resized whilst retaining their transparent backgrounds.
* Better compression of generated images and smaller filesizes.

#### Incompatibilities

The following features work differently or are otherwise compatible with timthumb. You might want to stick to it if you depend on any of these things:

* Progressive JPEGs are saved by default unless the parameter `p` is explicitly set to `0`.

### License and Credits

This software is licensed under an MIT open source license, see LICENSE.txt for details.

&copy; 2013 Sam Pospischil (pospi at spadgos.com)<br />
Initially developed at [Map Creative](http://mapcreative.com.au).

### TODO

* Add support for scaling animated gifs whilst retaining animation
* Support for ImageMagick 3 and under in 'inner fit' and 'inner fill' crop modes
* Extension for processing external images
