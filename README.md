# JW Watermark

[![Latest Version](https://img.shields.io/packagist/v/jw_301/.svg?style=flat-square)](https://github.com/DMKEBUSINESSGMBH/mksamlauth/releases)

This extension adds a Fluid ViewHelper that adds a Watermark image on top of an image.

## Installation

Open a command console, enter your project directory and execute the following command to download the latest stable version of this package:

```
$ composer require jw_301/jw_watermark
```

This command requires you to have Composer installed globally, as explained in the installation chapter of the Composer documentation.

Alternatively, you can install it without composer through the Typo3 extension repository.

## Usage

To use the watermark viewhelper, first import the watermark ViewHelper namespace at the beginning of the fluid HTML file like this (add the second line to an existing html tag):

```
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers" 
	 xmlns:jw="http://typo3.org/ns/Jw301/Watermark/ViewHelpers"
	 data-namespace-typo3-fluid="true">
```

A typical usage then would look like this:

```
<a title="{file.title}" data-description="{file.description}" href="{jw:watermark(watermarkSrc: '/fileadmin/Watermark.png', watermarkOpacity: '1', watermarkBackgroundColor: 'FFFFFF', watermarkBackgroundOpacity: '0', watermarkOffset: 0, watermarkPositionVertical: 'bottom', src: file.uid, treatIdAsReference: 1, width: settings.media.popup.width, height: settings.media.popup.height)}">
    <f:render partial="Media/Rendering/Image" arguments="{file: file, dimensions: dimensions, settings: settings}" />
</a>
```

This will create a link to the image with the watermark image on top of it, with the preview (rendered through a partial) remaining unchanged.
     
The viewhelper can be used like the `f:uri.image` ViewHelper documented at https://docs.typo3.org/other/typo3/view-helper-reference/master/en-us/typo3/fluid/latest/Uri/Image.html with all its arguments.

The following additional arguments can be used to add the watermark:

```
watermarkSrc
watermarkTreatIdAsReference
watermarkImage
```
The same as *src*, *treatIdAsReference*, *image* from the `f:uri.image` ViewHelper, but for the watermark image.

```
watermarkOpacity
```
Opacity value for the watermark image (0=fully transparent, 1=fully opaque)

```
watermarkBackgroundColor
```
A color hex code to set a background color of the watermark

```
watermarkBackgroundOpacity
```
Opacity for the background color of the watermark (0=fully transparent, 1=fully opaque)

```
watermarkPositionHorizontal
```
Horizontal position of the watermark in the image (left/center/right)

```
watermarkPositionVertical
```
Vertical position of the watermark in the image (top/middle/bottom)

```
watermarkOffset
```
Offset from the edge in pixels (horizontally and vertically)

## Restrictions

Currently, only PNG watermarks have been tested, but JPG Watermarks may work as well. Support for SVG ist unknown.

Also, the ViewHelper will only apply watermarks to JPEG images, not any other image formats.

## Issues and feature requests

Please report issues and request features at https://github.com/JW301/jw_watermark/issues.