# AGENTS.md — working on jw_watermark

Guidance for AI assistants and contributors working in this repository.

## What this extension is

A TYPO3 extension providing two Fluid ViewHelpers that composite a watermark image onto another
image. It has no plugins, no backend module, no database tables — just the ViewHelpers and their
supporting code.

| ViewHelper | Class | Returns |
|---|---|---|
| `<jw:watermarkedImage />` | `Classes/ViewHelpers/WatermarkedImageViewHelper.php` | a complete `<img>` tag |
| `{jw:uri.watermarkedImage(...)}` | `Classes/ViewHelpers/Uri/WatermarkedImageViewHelper.php` | the image URL only |

The actual compositing lives in `Uri\WatermarkedImageViewHelper::processImage()`, a public static
method the tag ViewHelper also calls. Change image logic there, not in two places.

## One codebase, three TYPO3 majors

The extension supports **TYPO3 v12.4, v13.4 and v14** from a single codebase. Do not fork per
version. The points where the versions differ are already bridged — keep them working when you
change things:

- **`render()`, not `renderStatic()`.** The `CompileWithRenderStatic` trait and `renderStatic()`
  are gone in Fluid 5 (v14) and deprecated in Fluid 4 (v13). A normal `render()` works everywhere.
- **No `registerTagAttribute()` / `registerUniversalTagAttributes()`.** Removed in Fluid 5.
  Undeclared attributes (`class`, `alt`, `data-*`, `loading`, …) pass through to the tag via the
  base class's `initialize()` in all versions, so `alt`/`title` are read from
  `$this->additionalArguments`.
- **`ProcessedFile::getTask()` removed in v14.** Use the `getProcessingTask()` shim, which falls
  back to `TaskTypeRegistry` when the method is absent.
- **`ProcessedFileRepository::add()` gained a second parameter (the task) in v14.** The
  `persistProcessedFile()` helper detects the arity by reflection.
- **`webp_quality` / `avif_quality` exist only from v13.** `writeImage()` defaults them.

When touching any of these, verify against a real installation of **each** supported major (see
Testing).

## How watermark application and caching work

- TYPO3 folds the `watermark*` processing instructions into the checksum of the processed file, so
  **every watermark configuration produces its own processed file.** We do not track that ourselves.
- The watermark is applied only when the processed file was just (re-)generated
  (`usesOriginalFile() || isUpdated()`); cached files are served untouched. There is **no** metadata
  marker anymore — an earlier IPTC/tEXt copyright marker was removed as fragile and unnecessary.
- In the `usesOriginalFile()` case (no resizing requested), the modified file must be persisted via
  `persistProcessedFile()`, otherwise the record keeps pointing at the original and the watermark is
  recomputed on every request.

## Supported image formats

JPEG, PNG, WebP and AVIF, driven by the `WRITERS` map and checked at runtime with
`function_exists()` (WebP/AVIF are build-dependent in GD). Unsupported formats are passed through
unwatermarked rather than throwing. GIF (palette banding, animation loss) and SVG (vector) are
deliberately excluded. Adding a raster format means one `WRITERS` entry plus the matching
`image*()` call in `writeImage()`.

## Testing

There is no automated test suite in the repository. Verify changes by **rendering a template in a
real TYPO3 installation of each supported major version** and inspecting the output:

- all four formats, as both the tag and the URI ViewHelper;
- responsive `srcset` built from several `jw:uri.watermarkedImage` calls;
- pass-through of arbitrary HTML attributes and a deliberately empty `alt`;
- a **second** request to the same page, confirming the processed files are not regenerated
  (cache path);
- alpha preservation for PNG/WebP/AVIF base images.

Note that the image processor must support the format (GraphicsMagick 1.3, for instance, handles
WebP but not AVIF) and that `webp`/`avif` must be in `$GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']`
(present by default from v13).

## Repository conventions

- **Language: English.** Code comments, README and this file are in English.
- **No AI attribution in commit messages.** Do not add `Co-Authored-By: Claude …` or similar
  trailers. AI assistance is disclosed transparently in prose instead — see the README's
  "Development notes".
- Keep the two ViewHelpers' argument lists in sync; they intentionally accept the same arguments.
