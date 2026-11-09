<?php

namespace JwTue\Watermark\ViewHelpers\Uri;

use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;

class WatermarkedImageViewHelper extends AbstractViewHelper
{
    public const MIME_JPEG = 'image/jpeg';
    public const MIME_PNG = 'image/png';
    public const MIME_WEBP = 'image/webp';
    public const MIME_AVIF = 'image/avif';

    /**
     * Image formats a watermark can be applied to.
     *
     * The key is the MIME type, the value the GD function used to write it. Not every GD
     * build supports all formats — WebP and AVIF are build-dependent — so availability is
     * checked at runtime. If a format cannot be written, the image is passed through
     * unchanged instead of throwing an exception.
     *
     * Deliberately excluded:
     * - GIF: palette-based (max. 256 colors), the watermark would produce visible banding.
     *   Also, imagecreatefromstring() only reads the first frame of an animated GIF — the
     *   animation would be lost silently.
     * - SVG: vector format, GD cannot process it.
     */
    private const WRITERS = [
        self::MIME_JPEG => 'imagejpeg',
        self::MIME_PNG  => 'imagepng',
        self::MIME_WEBP => 'imagewebp',
        self::MIME_AVIF => 'imageavif',
    ];

    /** Formats with an alpha channel — transparency must be preserved when writing. */
    private const ALPHA_FORMATS = [self::MIME_PNG, self::MIME_WEBP, self::MIME_AVIF];

    /** Reverse lookup for determineOutputMimeType(), which only knows the target extension. */
    private const MIME_BY_EXTENSION = [
        'jpg'  => self::MIME_JPEG,
        'jpeg' => self::MIME_JPEG,
        'png'  => self::MIME_PNG,
        'webp' => self::MIME_WEBP,
        'avif' => self::MIME_AVIF,
    ];

    /**
     * Initialize arguments
     */
    public function initializeArguments(): void
    {
        $this->registerArgument('src', 'string', 'src', false, '');
        $this->registerArgument('treatIdAsReference', 'bool', 'given src argument is a sys_file_reference record', false, false);
        $this->registerArgument('image', 'object', 'image');
        $this->registerArgument('crop', 'string|bool|array', 'overrule cropping of image (setting to FALSE disables the cropping set in FileReference)');
        $this->registerArgument('cropVariant', 'string', 'select a cropping variant, in case multiple croppings have been specified or stored in FileReference', false, 'default');
        $this->registerArgument('fileExtension', 'string', 'Custom file extension to use');

        $this->registerArgument('width', 'string', 'width of the image. This can be a numeric value representing the fixed width of the image in pixels. But you can also perform simple calculations by adding "m" or "c" to the value. See imgResource.width for possible options.');
        $this->registerArgument('height', 'string', 'height of the image. This can be a numeric value representing the fixed height of the image in pixels. But you can also perform simple calculations by adding "m" or "c" to the value. See imgResource.width for possible options.');
        $this->registerArgument('minWidth', 'int', 'minimum width of the image');
        $this->registerArgument('minHeight', 'int', 'minimum height of the image');
        $this->registerArgument('maxWidth', 'int', 'maximum width of the image');
        $this->registerArgument('maxHeight', 'int', 'maximum height of the image');
        $this->registerArgument('absolute', 'bool', 'Force absolute URL', false, false);

        $this->registerArgument('watermarkSrc', 'string', 'watermarkSrc');
        $this->registerArgument('watermarkTreatIdAsReference', 'bool', 'given watermarkSrc argument is a sys_file_reference record', false, false);
        $this->registerArgument('watermarkImage', 'object', 'watermarkImage');
        $this->registerArgument('watermarkOpacity', 'string', 'opacity value for the watermark image (0=fully transparent, 1=fully opaque)');
        $this->registerArgument('watermarkBackgroundColor', 'string', 'hex code for the background color of the watermark');
        $this->registerArgument('watermarkBackgroundOpacity', 'string', 'opacity value for the background color (0=fully transparent, 1=fully opaque)');
        $this->registerArgument('watermarkOffset', 'int', 'offset from the edge (both axes) in pixels. Overrules watermarkOffsetX and watermarkOffsetY. If watermarkOffsetRelative is set, this is interpreted as a percentage value of the image', false, null);
        $this->registerArgument('watermarkOffsetRelative', 'string', 'Interpret watermark offset relative to image dimension (values: width, height, short, long). Default: none', false, false);
        $this->registerArgument('watermarkOffsetX', 'int', 'offset from the left or right edge in pixels. If watermarkOffsetXRelative is set, this is interpreted as a percentage value of the image', false, null);
        $this->registerArgument('watermarkOffsetXRelative', 'string', 'Interpret the horizontal watermark offset relative to image dimension (values: width, height, short, long). Default: none', false, false);
        $this->registerArgument('watermarkOffsetY', 'int', 'offset from the top or bottom edge in pixels. If watermarkOffsetYRelative is set, this is interpreted as a percentage value of the image', false, null);
        $this->registerArgument('watermarkOffsetYRelative', 'string', 'Interpret the vertical watermark offset relative to image dimension (values: width, height, short, long). Default: none', false, false);
        $this->registerArgument('watermarkPositionHorizontal', 'string', 'horizontal position in the image (left/center/right)');
        $this->registerArgument('watermarkPositionVertical', 'string', 'vertical position in the image (top/middle/bottom)');
        $this->registerArgument('watermarkWidth', 'int', 'Water mark width in pixels. If watermarkWidthRelative is set, this is interpreted as a percentage value of the image.', false, false);
        $this->registerArgument('watermarkHeight', 'int', 'Water mark height in pixels. If watermarkHeightRelative is set, this is interpreted as a percentage value of the image.', false, false);
        $this->registerArgument('watermarkWidthRelative', 'string', 'Interpret watermark width relative to image dimension (values: width, height, short, long). Default: none', false, false);
        $this->registerArgument('watermarkHeightRelative', 'string', 'Interpret watermark width relative to image dimension (values: width, height, short, long). Default: none', false, false);
    }

    /**
     * Resizes the image (if required), applies the watermark and returns the path.
     *
     * This used to be a static renderStatic() together with the CompileWithRenderStatic
     * trait. Both are removed in Fluid 5 (TYPO3 v14) and already logged as deprecated in
     * Fluid 4 (TYPO3 v13). A regular render() works identically in Fluid 2, 4 and 5.
     *
     * @throws Exception
     */
    public function render(): string
    {
        $arguments = $this->arguments;
        $renderingContext = $this->renderingContext;

        $src = $arguments['src'];
        $image = $arguments['image'];
        $treatIdAsReference = $arguments['treatIdAsReference'];
        $absolute = $arguments['absolute'];

        if (($src === '' && $image === null) || ($src !== '' && $image !== null)) {
            throw new Exception(
                self::getExceptionMessage('You must either specify a string src or a File object.', $renderingContext),
                1382284106
            );
        }

        try {
            $imageService = self::getImageService();
            $image = $imageService->getImage($src, $image, $treatIdAsReference);

            $processedImage = self::processImage($image, $arguments, $imageService, $renderingContext);

            return $imageService->getImageUri($processedImage, $absolute);
        } catch (ResourceDoesNotExistException $e) {
            // thrown if file does not exist
            throw new Exception(self::getExceptionMessage($e->getMessage(), $renderingContext), 1709565153, $e);
        } catch (\UnexpectedValueException $e) {
            // thrown if a file has been replaced with a folder
            throw new Exception(self::getExceptionMessage($e->getMessage(), $renderingContext), 1709565154, $e);
        } catch (\InvalidArgumentException $e) {
            // thrown if file storage does not exist
            throw new Exception(self::getExceptionMessage($e->getMessage(), $renderingContext), 1709565155, $e);
        }
    }

    /**
     * Applies resizing/cropping and then the watermark.
     *
     * $renderingContext is optional and only used for meaningful error messages. The
     * parameter used to be missing while still being referenced in the catch blocks, so
     * error handling failed on an undefined variable.
     */
    public static function processImage(FileInterface $image, array $arguments, ImageService $imageService, ?RenderingContextInterface $renderingContext = null): FileInterface
    {

        $watermarkSrc = $arguments['watermarkSrc'];
        $watermarkImage = $arguments['watermarkImage'];
        $watermarkTreatIdAsReference = $arguments['watermarkTreatIdAsReference'];

        $cropString = $arguments['crop'];
        if ($cropString === null && $image->hasProperty('crop') && $image->getProperty('crop')) {
            $cropString = $image->getProperty('crop');
        }

        $cropVariantCollection = CropVariantCollection::create((string)$cropString);
        $cropVariant = $arguments['cropVariant'] ?: 'default';
        $cropArea = $cropVariantCollection->getCropArea($cropVariant);
        $processingInstructions = [
            'width' => $arguments['width'],
            'height' => $arguments['height'],
            'minWidth' => $arguments['minWidth'],
            'minHeight' => $arguments['minHeight'],
            'maxWidth' => $arguments['maxWidth'],
            'maxHeight' => $arguments['maxHeight'],
            'crop' => $cropArea->isEmpty() ? null : $cropArea->makeAbsoluteBasedOnFile($image),
            'watermarkSrc' => $arguments['watermarkSrc'],
            'watermarkTreatIdAsReference' => $arguments['watermarkTreatIdAsReference'],
            'watermarkImage' => $arguments['watermarkImage'],
            'watermarkOpacity' => $arguments['watermarkOpacity'],
            'watermarkBackgroundColor' => $arguments['watermarkBackgroundColor'],
            'watermarkBackgroundOpacity' => $arguments['watermarkBackgroundOpacity'],
            'watermarkOffset' => $arguments['watermarkOffset'],
            'watermarkOffsetRelative' => $arguments['watermarkOffsetRelative'],
            'watermarkOffsetX' => $arguments['watermarkOffsetX'],
            'watermarkOffsetXRelative' => $arguments['watermarkOffsetXRelative'],
            'watermarkOffsetY' => $arguments['watermarkOffsetY'],
            'watermarkOffsetYRelative' => $arguments['watermarkOffsetYRelative'],
            'watermarkPositionHorizontal' => $arguments['watermarkPositionHorizontal'],
            'watermarkPositionVertical' => $arguments['watermarkPositionVertical'],
            'watermarkWidth' => $arguments['watermarkWidth'],
            'watermarkWidthRelative' => $arguments['watermarkWidthRelative'],
            'watermarkHeight' => $arguments['watermarkHeight'],
            'watermarkHeightRelative' => $arguments['watermarkHeightRelative'],
        ];

        // Only when set: an empty fileExtension would make ImageCropScaleMaskTask treat the
        // empty string as the target extension instead of deriving one from the source.
        if (!empty($arguments['fileExtension'])) {
            $processingInstructions['fileExtension'] = $arguments['fileExtension'];
        }

        $processedImage = $imageService->applyProcessingInstructions($image, $processingInstructions);

        // Not getMimeType(): without a conversion that reports the original's format, which
        // need not match the extension TYPO3 reserved for the processed file.
        $mimeType = self::determineOutputMimeType($processedImage);

        // When does the watermark have to be applied?
        //
        // TYPO3 folds the given watermark* parameters into the checksum of the processed
        // file (AbstractTask::getChecksumData() serialises the full configuration). Every
        // watermark variant therefore gets its own file — so all we need to know is whether
        // TYPO3 has just (re-)generated it:
        //
        //   isUpdated() === true   freshly processed, watermark not applied yet
        //   isUpdated() === false  served from cache, already carries it
        //
        // usesOriginalFile() means no resizing was necessary and TYPO3 returns the original
        // file. We must not deliver that unchanged, so in this case we always watermark.
        if (self::isSupportedMimeType($mimeType)
            && ($processedImage->usesOriginalFile() || $processedImage->isUpdated())) {

            try {
                $watermark = $imageService->getImage($watermarkSrc, $watermarkImage, $watermarkTreatIdAsReference);

                $imageBitmap = imagecreatefromstring($processedImage->getContents());
                imagealphablending($imageBitmap, true);
                imagesavealpha($imageBitmap, true);

                $watermarkBitmap = imagecreatefromstring($watermark->getContents());
                imagealphablending($watermarkBitmap, true);
                imagesavealpha($watermarkBitmap, true);

                $imageWidth = imagesx($imageBitmap);
                $imageHeight = imagesy($imageBitmap);
                $imageShort = min($imageWidth, $imageHeight);
                $imageLong = max($imageWidth, $imageHeight);
                $watermarkRatio = imagesx($watermarkBitmap) / imagesy($watermarkBitmap);

                $newWatermarkWidth = null;
                $newWatermarkHeight = null;

                if ($arguments['watermarkWidth']) {
                    $newWidth = $arguments['watermarkWidth'];
                    $newWidthPercentage = $arguments['watermarkWidth'] / 100;
                    if ($arguments['watermarkWidthRelative'] == 'width') {
                        $newWatermarkWidth = $imageWidth * $newWidthPercentage;
                    } elseif ($arguments['watermarkWidthRelative'] == 'height') {
                        $newWatermarkWidth = $imageHeight * $newWidthPercentage;
                    } elseif ($arguments['watermarkWidthRelative'] == 'short') {
                        $newWatermarkWidth = $imageShort * $newWidthPercentage;
                    } elseif ($arguments['watermarkWidthRelative'] == 'long') {
                        $newWatermarkWidth = $imageLong * $newWidthPercentage;
                    } else {
                        $newWatermarkWidth = $newWidth;
                    }
                }
                if ($arguments['watermarkHeight']) {
                    $newHeight = $arguments['watermarkHeight'];
                    $newHeightPercentage = $arguments['watermarkHeight'] / 100;
                    if ($arguments['watermarkHeightRelative'] == 'width') {
                        $newWatermarkHeight = $imageWidth * $newHeightPercentage;
                    } elseif ($arguments['watermarkHeightRelative'] == 'height') {
                        $newWatermarkHeight = $imageHeight * $newHeightPercentage;
                    } elseif ($arguments['watermarkHeightRelative'] == 'short') {
                        $newWatermarkHeight = $imageShort * $newHeightPercentage;
                    } elseif ($arguments['watermarkHeightRelative'] == 'long') {
                        $newWatermarkHeight = $imageLong * $newHeightPercentage;
                    } else {
                        $newWatermarkHeight = $newHeight;
                    }
                }

                if ($newWatermarkWidth == null && $newWatermarkHeight == null) {
                    $newWatermarkWidth = imagesx($watermarkBitmap);
                    $newWatermarkHeight = imagesy($watermarkBitmap);
                } elseif ($newWatermarkWidth != null && $newWatermarkHeight == null) {
                    $newWatermarkHeight = $newWatermarkWidth / $watermarkRatio;
                } elseif ($newWatermarkWidth == null && $newWatermarkHeight != null) {
                    $newWatermarkWidth = $newWatermarkHeight * $watermarkRatio;
                }

                // The values above come from multiplications and are therefore floats.
                // GD expects integers; the implicit conversion is deprecated since PHP 8.1.
                $newWatermarkWidth = max(1, (int)round($newWatermarkWidth));
                $newWatermarkHeight = max(1, (int)round($newWatermarkHeight));

                $watermarkResizedBitmap = imagecreatetruecolor($newWatermarkWidth, $newWatermarkHeight);
                imagealphablending($watermarkResizedBitmap, true);
                imagesavealpha($watermarkResizedBitmap, true);
                imagefill($watermarkResizedBitmap, 0, 0, imagecolorallocatealpha($watermarkResizedBitmap, 0, 0, 0, 127));
                imagecopyresampled($watermarkResizedBitmap, $watermarkBitmap, 0, 0, 0, 0, $newWatermarkWidth, $newWatermarkHeight, imagesx($watermarkBitmap), imagesy($watermarkBitmap));

                $watermarkOpacityBitmap = imagecreatetruecolor($newWatermarkWidth, $newWatermarkHeight);
                imagealphablending($watermarkOpacityBitmap, true);
                imagesavealpha($watermarkOpacityBitmap, true);
                imagefill($watermarkOpacityBitmap, 0, 0, imagecolorallocatealpha($watermarkOpacityBitmap, 0, 0, 0, 127));

                $opacity = $arguments['watermarkOpacity'] ?: 1;

                for ($x = 0; $x < imagesx($watermarkResizedBitmap); $x++) {
                    for ($y = 0; $y < imagesy($watermarkResizedBitmap); $y++) {
                        $color = imagecolorat($watermarkResizedBitmap, $x, $y);

                        $rgba = [
                            'red' => (($color >> 16) & 0xFF),
                            'green' => (($color >> 8) & 0xFF),
                            'blue' => ($color  & 0xFF),
                            'alpha' => (($color & 0x7F000000) >> 24),
                        ];

                        $rgba['alpha'] = 127 - $rgba['alpha'];
                        $rgba['alpha'] *= $opacity;
                        $rgba['alpha'] = 127 - $rgba['alpha'];

                        $color = imagecolorallocatealpha($watermarkOpacityBitmap, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
                        imagesetpixel($watermarkOpacityBitmap, $x, $y, $color);
                    }
                }

                $backgroundcolorhex = $arguments['watermarkBackgroundColor'] ?: '000000';
                $backgroundcoloralpha = 127 - ($arguments['watermarkBackgroundOpacity'] ?: 0) * 127;

                $offsetX = ($arguments['watermarkOffset'] ?? $arguments['watermarkOffsetX']) ?: 0;
                $offsetXPercentage = $offsetX / 100;
                $offsetX = match ($arguments['watermarkOffsetRelative'] ?: $arguments['watermarkOffsetXRelative']) {
                    'width' => $imageWidth * $offsetXPercentage,
                    'height' => $imageHeight * $offsetXPercentage,
                    'short' => $imageShort * $offsetXPercentage,
                    'long' => $imageLong * $offsetXPercentage,
                    default => $offsetX,
                };

                $offsetY = ($arguments['watermarkOffset'] ?? $arguments['watermarkOffsetY']) ?: 0;
                $offsetYPercentage = $offsetY / 100;
                $offsetY = match ($arguments['watermarkOffsetRelative'] ?: $arguments['watermarkOffsetYRelative']) {
                    'width' => $imageWidth * $offsetYPercentage,
                    'height' => $imageHeight * $offsetYPercentage,
                    'short' => $imageShort * $offsetYPercentage,
                    'long' => $imageLong * $offsetYPercentage,
                    default => $offsetY,
                };

                $topleftx = $offsetX;
                $toplefty = $offsetY;

                if ($arguments['watermarkPositionHorizontal']) {
                    if ($arguments['watermarkPositionHorizontal'] == 'center') {
                        $topleftx = (imagesx($imageBitmap) / 2) - (imagesx($watermarkOpacityBitmap) / 2);
                    } elseif ($arguments['watermarkPositionHorizontal'] == 'right') {
                        $topleftx = imagesx($imageBitmap) - imagesx($watermarkOpacityBitmap) - $offsetX;
                    }
                }
                if ($arguments['watermarkPositionVertical']) {
                    if ($arguments['watermarkPositionVertical'] == 'middle') {
                        $toplefty = (imagesy($imageBitmap) / 2) - (imagesy($watermarkOpacityBitmap) / 2);
                    } elseif ($arguments['watermarkPositionVertical'] == 'bottom') {
                        $toplefty = imagesy($imageBitmap) - imagesy($watermarkOpacityBitmap) - $offsetY;
                    }
                }

                // Again: position and offset may result from percentage calculations.
                $topleftx = (int)round($topleftx);
                $toplefty = (int)round($toplefty);

                $backgroundcolor = [hexdec(substr($backgroundcolorhex, 0, 2)), hexdec(substr($backgroundcolorhex, 2, 2)), hexdec(substr($backgroundcolorhex, 4, 2))];

                $bgcolor = imagecolorallocatealpha($imageBitmap, $backgroundcolor[0], $backgroundcolor[1], $backgroundcolor[2], (int)round($backgroundcoloralpha));
                imagefilledrectangle($imageBitmap, $topleftx, $toplefty, $topleftx + imagesx($watermarkOpacityBitmap), $toplefty + imagesy($watermarkOpacityBitmap), $bgcolor);

                imagecopy($imageBitmap, $watermarkOpacityBitmap, $topleftx, $toplefty, 0, 0, imagesx($watermarkOpacityBitmap), imagesy($watermarkOpacityBitmap));

                $tmpname = tempnam(sys_get_temp_dir(), 'typo3watermark_' . rand());

                self::writeImage($imageBitmap, $tmpname, $mimeType);

                $wasUsingOriginalFile = $processedImage->usesOriginalFile();
                if ($wasUsingOriginalFile) {
                    // Without resizing, TYPO3 returns the original file, whose identifier is
                    // empty; updateWithLocalFile() then throws ("Cannot update original
                    // file!"). setName() sets name and identifier to a file in the processing
                    // folder and makes the call possible.
                    $processedImage->setName(self::getProcessingTask($processedImage)->getTargetFilename());
                }
                $processedImage->updateWithLocalFile($tmpname);

                // The core already persists the processed file in
                // FileProcessingService::process() — i.e. before we modify it here. Without
                // the add() below, the record would keep pointing at the original file;
                // TYPO3 would return the original again on the next request and the watermark
                // would be recomputed on *every* page load. add() decides on its own, via
                // isPersisted(), whether to insert or update.
                if ($wasUsingOriginalFile) {
                    // Without a resize TYPO3 never measured the image, so the record would
                    // keep width/height at 0 — and the tag ViewHelper renders those as
                    // width=""/height="" (v12/v13) or omits them (v14). We have the bitmap,
                    // so we know the real values. The watermark never changes them.
                    $processedImage->updateProperties([
                        'width' => imagesx($imageBitmap),
                        'height' => imagesy($imageBitmap),
                    ]);
                    self::persistProcessedFile($processedImage);
                }

                @unlink($tmpname);
            } catch (ResourceDoesNotExistException $e) {
                // thrown if file does not exist
                throw new Exception(self::getExceptionMessage($e->getMessage(), $renderingContext), 1709565156, $e);
            } catch (\UnexpectedValueException $e) {
                // thrown if a file has been replaced with a folder
                throw new Exception(self::getExceptionMessage($e->getMessage(), $renderingContext), 1709565157, $e);
            } catch (\InvalidArgumentException $e) {
                // thrown if file storage does not exist
                throw new Exception(self::getExceptionMessage($e->getMessage(), $renderingContext), 1709565158, $e);
            }
        }
        return $processedImage;
    }

    private static function isSupportedMimeType(?string $mimeType): bool
    {
        return isset(self::WRITERS[$mimeType]) && function_exists(self::WRITERS[$mimeType]);
    }

    /**
     * The format the watermarked file has to be written in.
     *
     * Usually that is simply the processed file's own mime type. The usesOriginalFile() case
     * is different: there TYPO3 did not convert anything, so getMimeType() still reports the
     * *original's* type — while the target filename TYPO3 has reserved may well carry another
     * extension. Two configuration points cause that:
     *
     * - a format missing from GFX/imagefile_ext is not processed at all (v12 ships without
     *   webp and avif there);
     * - from v14, GraphicalFunctions::determineDefaultProcessingFileExtension() maps every
     *   format missing from GFX/imageFileConversionFormats to the 'default' entry, i.e. PNG,
     *   and avif is not in that list.
     *
     * Writing the original's format would then put WebP or AVIF bytes into a file named
     * .png, which the web server serves as Content-Type: image/png. Browsers sniff images
     * and still display it, but the declared type is wrong for everything else that reads
     * it. So the target extension decides, and the bitmap is re-encoded accordingly.
     */
    private static function determineOutputMimeType($processedImage): ?string
    {
        $mimeType = $processedImage->getMimeType();

        if (!$processedImage->usesOriginalFile()) {
            return $mimeType;
        }

        $targetFilename = self::getProcessingTask($processedImage)->getTargetFilename();
        $extension = strtolower(pathinfo($targetFilename, PATHINFO_EXTENSION));

        return self::MIME_BY_EXTENSION[$extension] ?? $mimeType;
    }

    /**
     * Returns the processing task for a processed file.
     *
     * ProcessedFile::getTask() exists up to TYPO3 v13; in v14 the method is removed. The
     * TaskTypeRegistry can, however, build the task in all three versions from the task type
     * and configuration — both of which ProcessedFile still provides.
     */
    private static function getProcessingTask($processedImage)
    {
        if (method_exists($processedImage, 'getTask')) {
            return $processedImage->getTask();
        }

        return GeneralUtility::makeInstance(TaskTypeRegistry::class)->getTaskForType(
            $processedImage->getTaskIdentifier(),
            $processedImage,
            $processedImage->getProcessingConfiguration()
        );
    }

    /**
     * Registers the modified file in sys_file_processedfile.
     *
     * As of TYPO3 v14, add() additionally expects the task.
     */
    private static function persistProcessedFile($processedImage): void
    {
        $repository = GeneralUtility::makeInstance(ProcessedFileRepository::class);
        $parameterCount = (new \ReflectionMethod($repository, 'add'))->getNumberOfParameters();

        if ($parameterCount > 1) {
            $repository->add($processedImage, self::getProcessingTask($processedImage));
            return;
        }

        $repository->add($processedImage);
    }

    /**
     * Writes the finished image in its original format.
     *
     * The quality settings come from the TYPO3 configuration; webp_quality and avif_quality
     * only exist from v13 on, hence the default value.
     */
    private static function writeImage($imageBitmap, string $file, string $mimeType): void
    {
        if (in_array($mimeType, self::ALPHA_FORMATS, true)) {
            // Alpha blending must be on while compositing but off when saving —
            // otherwise GD does not write the alpha channel.
            imagealphablending($imageBitmap, false);
            imagesavealpha($imageBitmap, true);
        }

        $gfxConf = $GLOBALS['TYPO3_CONF_VARS']['GFX'] ?? [];
        $quality = static fn(string $key): int => MathUtility::forceIntegerInRange($gfxConf[$key] ?? 85, 10, 100, 85);

        switch ($mimeType) {
            case self::MIME_PNG:
                // imagepng()'s third argument is the zlib compression level (0-9), not a
                // quality value. Omitting it lets GD choose its default.
                imagepng($imageBitmap, $file);
                break;
            case self::MIME_WEBP:
                imagewebp($imageBitmap, $file, $quality('webp_quality'));
                break;
            case self::MIME_AVIF:
                imageavif($imageBitmap, $file, $quality('avif_quality'));
                break;
            default:
                imagejpeg($imageBitmap, $file, $quality('jpg_quality'));
        }
    }

    protected static function getExceptionMessage(string $detailedMessage, ?RenderingContextInterface $renderingContext = null): string
    {
        $request = $renderingContext?->getRequest();
        if ($request instanceof RequestInterface) {
            $currentContentObject = $request->getAttribute('currentContentObject');
            if ($currentContentObject instanceof ContentObjectRenderer) {
                return sprintf('Unable to render image uri in "%s": %s', $currentContentObject->currentRecord, $detailedMessage);
            }
        }
        return sprintf('Unable to render image uri: %s', $detailedMessage);
    }

    protected static function getImageService(): ImageService
    {
        return GeneralUtility::makeInstance(ImageService::class);
    }
}
