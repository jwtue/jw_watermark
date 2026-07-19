# JW Watermark

[![Latest Version](https://img.shields.io/packagist/v/jwtue/jw_watermark.svg?style=flat-square)](https://packagist.org/packages/jwtue/jw_watermark)

This extension adds Fluid ViewHelpers that place a watermark image on top of an image.

Supports TYPO3 v12, v13 and v14.

## Installation

Open a command console, enter your project directory and execute the following command to
download the latest stable version of this package:

```
$ composer require jwtue/jw_watermark
```

This command requires you to have Composer installed globally, as explained in the
installation chapter of the Composer documentation.

Alternatively, you can install it without composer through the TYPO3 extension repository.

## The two ViewHelpers

The extension provides **two** ViewHelpers. They take the same arguments and produce the same
image — they differ only in what they return:

| ViewHelper | Corresponds to | Returns |
|---|---|---|
| `<jw:watermarkedImage />` | `<f:image />` | a complete `<img>` tag |
| `{jw:uri.watermarkedImage(...)}` | `{f:uri.image(...)}` | the image URL only |

**If you are replacing an existing `f:image` or `f:uri.image` call, pick the matching one.**
Every argument of the core ViewHelper works as documented for
[f:image](https://docs.typo3.org/other/typo3/view-helper-reference/main/en-us/typo3/fluid/latest/Image.html)
and
[f:uri.image](https://docs.typo3.org/other/typo3/view-helper-reference/main/en-us/typo3/fluid/latest/Uri/Image.html);
the `watermark*` arguments listed below are added on top.

`<jw:watermarkedImage />` also passes through any HTML attribute you set — `class`, `alt`,
`title`, `loading`, `decoding`, `data`, `aria` and so on — exactly like `f:image`.

## Usage

First import the ViewHelper namespace at the top of your Fluid template (add the second line
to an existing `html` tag):

```html
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:jw="http://typo3.org/ns/JwTue/Watermark/ViewHelpers"
      data-namespace-typo3-fluid="true">
```

In a template without an `html` tag (a DCE template, for example) use:

```
{namespace jw=JwTue\Watermark\ViewHelpers}
```

### As an image tag

```html
<jw:watermarkedImage src="{file.uid}" treatIdAsReference="1"
                     watermarkSrc="/fileadmin/Watermark.png"
                     watermarkOpacity="1"
                     watermarkPositionVertical="bottom"
                     watermarkOffset="5" watermarkOffsetRelative="long"
                     watermarkWidth="30" watermarkWidthRelative="long"
                     alt="{file.alternative}" class="img-responsive" />
```

### As a URL

```html
<a href="{jw:uri.watermarkedImage(src: file.uid, treatIdAsReference: 1,
         watermarkSrc: '/fileadmin/Watermark.png', watermarkOpacity: '1',
         watermarkPositionVertical: 'bottom',
         width: settings.media.popup.width, height: settings.media.popup.height)}">
    <f:render partial="Media/Rendering/Image" arguments="{file: file, settings: settings}" />
</a>
```

This links to the watermarked image while the preview (rendered through a partial) stays
unchanged.

### Responsive images and `srcset`

Every URL inside a `srcset` is a separate image and needs its own watermark. Replace each
`f:uri.image` call with `jw:uri.watermarkedImage` — the arguments are identical.

Because a `srcset` needs several URLs inside one attribute value, nesting the calls directly
gets hard to read and hard to quote correctly. Build the URLs with `f:variable` first:

```html
<f:variable name="wmSmall">{jw:uri.watermarkedImage(image: file, maxWidth: 768,
    watermarkSrc: '/fileadmin/Watermark.png', watermarkOpacity: '1',
    watermarkPositionVertical: 'bottom',
    watermarkWidth: 30, watermarkWidthRelative: 'long')}</f:variable>

<f:variable name="wmLarge">{jw:uri.watermarkedImage(image: file, maxWidth: 1200,
    watermarkSrc: '/fileadmin/Watermark.png', watermarkOpacity: '1',
    watermarkPositionVertical: 'bottom',
    watermarkWidth: 30, watermarkWidthRelative: 'long')}</f:variable>

<jw:watermarkedImage image="{file}" width="{dimensions.width}" height="{dimensions.height}"
    alt="{file.alternative}" title="{file.title}" class="img-responsive"
    watermarkSrc="/fileadmin/Watermark.png" watermarkOpacity="1"
    watermarkPositionVertical="bottom" watermarkWidth="30" watermarkWidthRelative="long"
    additionalAttributes="{srcset: '{wmSmall} 768w, {wmLarge} 1200w',
                           sizes: '(min-width: 1200px) 50vw, 100vw'}" />
```

Note that the `src` attribute needs its own `watermark*` arguments — the ones inside `srcset`
only apply to the URLs listed there.

Use `watermarkWidthRelative` here rather than a fixed `watermarkWidth`: the watermark then
scales with each variant instead of being tiny on the large one and oversized on the small one.

### Using it with `f:media`

`f:media` has no watermark counterpart, because it also renders video and audio. For images
there are two options:

1. **Replace `f:media` with `jw:watermarkedImage`** if you only ever render images at that
   point. This is the simpler route and gives you the full set of arguments.
2. **Keep `f:media`** and watermark only the URLs you build yourself — for instance inside
   `additionalAttributes.srcset`, as shown above. Note that the image in the `src` attribute
   is then rendered by `f:media` and stays **without** a watermark.

There is no way to make `f:media` itself apply a watermark to its own `src`.

## Watermark arguments

```
watermarkSrc
watermarkTreatIdAsReference
watermarkImage
```
The same as *src*, *treatIdAsReference*, *image* from the `f:uri.image` ViewHelper, but for the
watermark image.

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

```
watermarkOffsetRelative
```
If set, `watermarkOffset` is not interpreted as a pixel value, but as a percentage value
(0-100) of the image dimensions. Possible values are `width`, `height`, `long`, `short`. They
set the dimension that the relative value is based upon (width/height of the image, or the
long/short side of the image).

```
watermarkWidth
```
Width of the watermark in pixels

```
watermarkWidthRelative
```
If set, `watermarkWidth` is not interpreted as a pixel value, but as a percentage value
(0-100) of the image dimensions. Possible values are `width`, `height`, `long`, `short`.

```
watermarkHeight
```
Height of the watermark in pixels

```
watermarkHeightRelative
```
If set, `watermarkHeight` is not interpreted as a pixel value, but as a percentage value
(0-100) of the image dimensions. Possible values are `width`, `height`, `long`, `short`.

## Supported formats

Watermarks are applied to **JPEG, PNG, WebP and AVIF**. The alpha channel is preserved for
the formats that have one (PNG, WebP, AVIF).

WebP and AVIF depend on your GD build — the extension checks `imagewebp()` and `imageavif()`
at runtime and passes the image through unchanged if the function is missing, rather than
failing.

Two things are worth knowing, because they are about TYPO3 and your image processor rather
than about this extension:

- **TYPO3 v12 does not list `webp` and `avif`** in `$GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']`;
  v13 and v14 do. On v12, WebP images are therefore not scaled or cropped by TYPO3 — the
  watermark is still applied, but at the original size. Add the extensions to
  `imagefile_ext` if you need scaling there.
- **Your image processor needs to understand the format.** GraphicsMagick 1.3, for instance,
  handles WebP but not AVIF. Where the processor cannot scale, TYPO3 hands back the original
  and the watermark is applied at that size.

Deliberately not supported:

- **GIF** — palette-based with at most 256 colours, so the watermark would band visibly. Worse,
  only the first frame of an animated GIF would survive, silently dropping the animation.
- **SVG** — a vector format that GD cannot process.

As the watermark image itself, PNG is tested and recommended. JPG may work as well; SVG is
untested.

Quality is taken from the TYPO3 configuration: `jpg_quality`, `webp_quality` and
`avif_quality` (the latter two exist from v13 on; the extension falls back to 85).

## How caching works

The watermark parameters are part of the processing instructions handed to TYPO3, so every
distinct watermark configuration produces its own processed file. The watermark is applied
when TYPO3 has just (re-)generated that file, which is determined via
`ProcessedFile::isUpdated()`. Cached files are served as they are.

Practical consequences:

- Changing a `watermark*` argument creates a new processed file; the old one stays until you
  clear the processed files.
- Clearing the processed files in the Install Tool regenerates the watermarks on the next
  request.
- Processed images carry **no** copyright metadata. Earlier versions wrote the site name into
  an IPTC field to recognise already-watermarked files; that is no longer needed and has been
  removed. Note that image metadata does not survive re-encoding anyway — the visible
  watermark is the durable marker.

## Requirements

- TYPO3 v12.4, v13.4 or v14
- PHP 8.1 or newer
- GD with support for the formats you use

Tested by rendering the same template on TYPO3 12.4, 13.4 and 14.3 — all four formats as both
tag and URI ViewHelper, `srcset`, attribute pass-through, and a second request confirming that
nothing is regenerated.

## Issues and feature requests

Please report issues and request features at https://github.com/jwtue/jw_watermark/issues.
