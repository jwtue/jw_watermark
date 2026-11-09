# Manual test harness

There is no unit test suite for this extension — the behaviour worth testing is the interaction
between Fluid, FAL, the configured image processor and GD, and that only shows up in a real
installation. This harness automates the checklist that used to be done by hand, so running it is
cheap enough to do on every supported TYPO3 major before a release.

It boots TYPO3 in CLI mode and renders a Fluid template that uses both ViewHelpers. Nothing is
served over HTTP and no page, content element or TypoScript in the installation is touched.

## Running it

```sh
sh Tests/Manual/run.sh /path/to/typo3-install [php-binary]
```

Do that once per supported major (v12.4, v13.4, v14). The harness exits non-zero if a check fails.

Requirements: a Composer-based TYPO3 installation with this extension installed (a path repository
or a symlink under `vendor/jwtue/jw_watermark` works), a reachable database, and GD built with the
formats you want covered.

## What it checks

- both ViewHelpers for JPEG, PNG, WebP and AVIF, and that the two agree on the same processed file
- the watermark really ends up in the pixels, by probing the composited area
- untouched areas of the image stay untouched
- alpha survives for PNG, WebP and AVIF
- arbitrary HTML attributes (`class`, `data-*`, `loading`, `title`) pass through the tag ViewHelper
- a deliberately empty `alt` stays empty rather than being dropped
- `width`/`height` on the tag describe the image actually delivered
- the bytes of each processed file match the extension it is served under
- a responsive `srcset` built from three `jw:uri.watermarkedImage` calls, each at its own width
- a second render serves everything from cache: no new `sys_file_processedfile` records, identical
  URLs, and the files untouched down to their sha256

## Reading the output

`FAIL` is a defect. `warn` is a deviation that the environment explains rather than the code, and
the harness prints which:

- **"format preserved (no silent conversion)"** fires when TYPO3 converted the format. A format
  missing from `GFX/imagefile_ext` is not processed at all (v12 ships without `webp` and `avif`),
  and from v14 `GFX/imageFileConversionFormats` maps anything it does not list — `avif` among them
  — to PNG. The extension follows that decision, which is what the extension-matches-content check
  verifies.
- **"not resized, format is not processable here"** is the same cause seen from the other side: if
  the processor cannot read the format, no resize happens and the original dimensions are served.
  GraphicsMagick 1.3 reads WebP but not AVIF.

So on a GraphicsMagick setup, expect one warning on v13/v14 (AVIF) and two on v12 (WebP and AVIF).

## Fixtures

`make-images.php` writes them to `<install>/public/fileadmin/jw_watermark_test/`. The base image is
600x400 with its left half opaque blue, its right half fully transparent and a white marker block
near the top left; the transparent half is what proves alpha survives. The watermark is a 160x80 red
block with an alpha ramp, so both colour and alpha handling are visible in the result.

The fixtures and their FAL records stay in the installation between runs. Delete the folder (and the
matching `sys_file`/`sys_file_processedfile` rows, or just run the TYPO3 file-storage cleanup) to
remove them.
