## ImThumb

> A [timthumb](http://www.binarymoon.co.uk/projects/timthumb/) compatible image generation script, based on ImageMagick instead of GD.

### About

I originally wrote this because I was attempting to optimise the quality of timthumb generated images, and realised that the lower quality images actually had larger filesizes than the higher quality ones. I ended up digging in a bit and came to the conclusion that some of GD's internals, as well as the way timthumb uses it, were responsible. Rather than attempt to patch a very old and messy library and fight against GD, I decided to reimplement the same functionality on top of ImageMagick.

### Features

* Drop-in replacement, uses the same configuration files as timthumb does.
* Self-contained class file and accessible image handling API, rather than a messy script designed to run in its own environment.

### License and Credits

This software is licensed under an MIT open source license, see LICENSE.txt for details.

&copy; 2013 Sam Pospischil (pospi at spadgos.com)<br />
Initially developed at [Map Creative](http://mapcreative.com.au).

### TODO

* Add support for scaling animated gifs whilst retaining animation
* Support for ImageMagick 3 and under in 'inner fit' and 'inner fill' crop modes
* Image filters
* Extension for processing external images
