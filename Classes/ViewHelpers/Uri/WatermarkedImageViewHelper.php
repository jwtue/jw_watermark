<?php

namespace JwTue\Watermark\ViewHelpers\Uri;

use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;
use TYPO3\CMS\Core\Utility\MathUtility;

class WatermarkedImageViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

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
        $this->registerArgument('watermarkOffset', 'int', 'offset from the edge in pixels. If watermarkOffsetRelative is set, this is interpreted as a percentage value of the image', false, 0);
		$this->registerArgument('watermarkOffsetRelative', 'string', 'Interpret watermark offset relative to image dimension (values: width, height, short, long). Default: none', false, false);
        $this->registerArgument('watermarkPositionHorizontal', 'string', 'horizontal position in the image (left/center/right)');
        $this->registerArgument('watermarkPositionVertical', 'string', 'vertical position in the image (top/middle/bottom)');
		$this->registerArgument('watermarkWidth', 'int', 'Water mark width in pixels. If watermarkWidthRelative is set, this is interpreted as a percentage value of the image.', false, false);
		$this->registerArgument('watermarkHeight', 'int', 'Water mark height in pixels. If watermarkHeightRelative is set, this is interpreted as a percentage value of the image.', false, false);
		$this->registerArgument('watermarkWidthRelative', 'string', 'Interpret watermark width relative to image dimension (values: width, height, short, long). Default: none', false, false);
		$this->registerArgument('watermarkHeightRelative', 'string', 'Interpret watermark width relative to image dimension (values: width, height, short, long). Default: none', false, false);
    }

    /**
     * Resizes the image (if required) and returns its path. If the image was not resized, the path will be equal to $src
     *
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return string
     * @throws Exception
     */
    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext): string
    {		
	    $src = $arguments['src'];
        $image = $arguments['image'];
        $treatIdAsReference = $arguments['treatIdAsReference'];
        $absolute = $arguments['absolute'];

        if (($src === '' && $image === null) || ($src !== '' && $image !== null)) {
            throw new Exception($this->getExceptionMessage('You must either specify a string src or a File object.'), 1382284106);
        }

        try {
            $imageService = self::getImageService();
            $image = $imageService->getImage($src, $image, $treatIdAsReference);

			$processedImage = self::processImage($image, $arguments, $imageService);

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
        return '';
    }

	public static function processImage(FileInterface $image, array $arguments, ImageService $imageService): FileInterface {		
				
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
			'watermarkPositionHorizontal' => $arguments['watermarkPositionHorizontal'],
			'watermarkPositionVertical' => $arguments['watermarkPositionVertical'],
			'watermarkWidth' => $arguments['watermarkWidth'],
			'watermarkWidthRelative' => $arguments['watermarkWidthRelative'],
			'watermarkHeight' => $arguments['watermarkHeight'],
			'watermarkHeightRelative' => $arguments['watermarkHeightRelative']
		];
		
		$processedImage = $imageService->applyProcessingInstructions($image, $processingInstructions);
		
		$processedPath = $processedImage->getForLocalProcessing(false);
		
		$copyright = self::getCopyrightString($processedPath);
		$sysConf = $GLOBALS['TYPO3_CONF_VARS']['SYS'];
		
		if (($processedImage->getMimeType() == "image/jpeg") && (($processedImage->usesOriginalFile()) || ($copyright == null) || ($copyright != $sysConf['sitename']))) {	
		
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
					if ($arguments['watermarkWidthRelative'] == "width") {
						$newWatermarkWidth = $imageWidth * $newWidthPercentage;
					} else if ($arguments['watermarkWidthRelative'] == "height") {
						$newWatermarkWidth = $imageHeight * $newWidthPercentage;
					} else if ($arguments['watermarkWidthRelative'] == "short") {
						$newWatermarkWidth = $imageShort * $newWidthPercentage;
					} else if ($arguments['watermarkWidthRelative'] == "long") {
						$newWatermarkWidth = $imageLong * $newWidthPercentage;
					} else {
						$newWatermarkWidth = $newWidth;
					}
				}
				if ($arguments['watermarkHeight']) {
					$newHeight = $arguments['watermarkHeight'];
					$newHeightPercentage = $arguments['watermarkHeight'] / 100;
					if ($arguments['watermarkHeightRelative'] == "width") {
						$newWatermarkHeight = $imageWidth * $newHeightPercentage;
					} else if ($arguments['watermarkHeightRelative'] == "height") {
						$newWatermarkHeight = $imageHeight * $newHeightPercentage;
					} else if ($arguments['watermarkHeightRelative'] == "short") {
						$newWatermarkHeight = $imageShort * $newHeightPercentage;
					} else if ($arguments['watermarkHeightRelative'] == "long") {
						$newWatermarkHeight = $imageLong * $newHeightPercentage;
					} else {
						$newWatermarkHeight = $newHeight;
					}
				}

				if ($newWatermarkWidth == null && $newWatermarkHeight == null) {
					$newWatermarkWidth = imagesx($watermarkBitmap);
					$newWatermarkHeight = imagesy($watermarkBitmap);
				} else if ($newWatermarkWidth != null && $newWatermarkHeight == null) {
					$newWatermarkHeight = $newWatermarkWidth / $watermarkRatio;
				} else if ($newWatermarkWidth == null && $newWatermarkHeight != null) {
					$newWatermarkWidth = $newWatermarkHeight * $watermarkRatio;
				}

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
															
						$rgba = array(
							"red" => (($color >> 16) & 0xFF),
							"green" => (($color >> 8) & 0xFF),
							"blue" => ($color  & 0xFF),
							"alpha" => (($color & 0x7F000000) >> 24)
						);

					
						$rgba['alpha'] = 127-$rgba['alpha'];
						$rgba['alpha'] *= $opacity;
						$rgba['alpha'] = 127-$rgba['alpha'];
						
						$color = imagecolorallocatealpha($watermarkOpacityBitmap, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
						imagesetpixel($watermarkOpacityBitmap, $x, $y, $color);
					}
				}
				
				$backgroundcolorhex = $arguments['watermarkBackgroundColor'] ?: "000000";
				$backgroundcoloralpha = 127-($arguments['watermarkBackgroundOpacity'] ?: 0)*127;
				
				$offset = $arguments['watermarkOffset'] ?: 0;

				$offsetPercentage = $arguments['watermarkOffset'] / 100;
				if ($arguments['watermarkOffsetRelative'] == "width") {
					$offset = $imageWidth * $offsetPercentage;
				} else if ($arguments['watermarkOffsetRelative'] == "height") {
					$offset = $imageHeight * $offsetPercentage;
				} else if ($arguments['watermarkOffsetRelative'] == "short") {
					$offset = $imageShort * $offsetPercentage;
				} else if ($arguments['watermarkOffsetRelative'] == "long") {
					$offset = $imageLong * $offsetPercentage;
				}

				
				$topleftx = $offset;
				$toplefty = $offset;
				
				if ($arguments['watermarkPositionHorizontal']) {
					if ($arguments['watermarkPositionHorizontal'] == "center") {
						$topleftx = (imagesx($imageBitmap)/2)-(imagesx($watermarkOpacityBitmap)/2);
					} else if ($arguments['watermarkPositionHorizontal'] == "right") {
						$topleftx = imagesx($imageBitmap)-imagesx($watermarkOpacityBitmap)-$offset;
					}
				}
				if ($arguments['watermarkPositionVertical']) {
					if ($arguments['watermarkPositionVertical'] == "middle") {
						$toplefty = (imagesy($imageBitmap)/2)-(imagesy($watermarkOpacityBitmap)/2);
					} else if ($arguments['watermarkPositionVertical'] == "bottom") {
						$toplefty = imagesy($imageBitmap)-imagesy($watermarkOpacityBitmap)-$offset;
					}
				}
							
				$backgroundcolor = array(hexdec(substr($backgroundcolorhex, 0, 2)), hexdec(substr($backgroundcolorhex, 2, 2)), hexdec(substr($backgroundcolorhex, 4, 2)));
				
				$bgcolor = imagecolorallocatealpha($imageBitmap, $backgroundcolor[0], $backgroundcolor[1], $backgroundcolor[2], $backgroundcoloralpha);
				imagefilledrectangle($imageBitmap, $topleftx, $toplefty, $topleftx+imagesx($watermarkOpacityBitmap), $toplefty+imagesy($watermarkOpacityBitmap), $bgcolor);
				
				imagecopy($imageBitmap, $watermarkOpacityBitmap, $topleftx, $toplefty, 0, 0, imagesx($watermarkOpacityBitmap), imagesy($watermarkOpacityBitmap));
				
				$gfxConf = $GLOBALS['TYPO3_CONF_VARS']['GFX'];
				$jpegQuality = MathUtility::forceIntegerInRange($gfxConf['jpg_quality'], 10, 100, 75);
				
				$tmpname = tempnam(sys_get_temp_dir(), 'typo3watermark_'.rand());	
				
				imagejpeg($imageBitmap, $tmpname, $jpegQuality);
				
				self::setCopyrightString($tmpname, $sysConf['sitename']);
					
				if ($processedImage->usesOriginalFile()) {
					$processedImage->setName($processedImage->getTask()->getTargetFilename());
				}
				$processedImage->updateWithLocalFile($tmpname);
					
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

	const IPTC_ENCODING = "1#090";
	const IPTC_COPYRIGHT_STRING = "2#116";

	const IPTC_ENCODING_UTF8 = "\x1B%G";
	
	private static function setCopyrightString($file, $string) {
		
		$mode = 0;
		$meta = array(
			self::IPTC_ENCODING 		=> self::IPTC_ENCODING_UTF8,
			self::IPTC_COPYRIGHT_STRING => $string
		);	

		$binary = "";
        foreach($meta as $tag => $string)
        {
            $tag = explode("#", $tag);
            $binary .= self::iptc_maketag($tag[0], $tag[1], $string);
        }

        $content = iptcembed($binary, $file, $mode); 
		
		if ($content !== false) {
			if(file_exists($file)) unlink($file);

			$fp = fopen($file, "w");
			fwrite($fp, $content);
			fclose($fp);
		}
	}
	private static function getCopyrightString($file) {
		if (!file_exists($file)) return null;

		$meta = [];
        $info = null;
        $size = getimagesize($file, $info);

        if (isset($info["APP13"])) {
			$meta = iptcparse($info["APP13"]);
			if ($meta === false) {
				$meta = array();
			}
		}

		return isset($meta[self::IPTC_COPYRIGHT_STRING]) ? $meta[self::IPTC_COPYRIGHT_STRING][0] : null;
	}
	
	private static function iptc_maketag($rec, $data, $value)
    {
        $length = strlen($value);
        $retval = chr(0x1C) . chr($rec) . chr($data);

        if($length < 0x8000)
        {
            $retval .= chr($length >> 8) .  chr($length & 0xFF);
        }
        else
        {
            $retval .= chr(0x80) . 
                       chr(0x04) . 
                       chr(($length >> 24) & 0xFF) . 
                       chr(($length >> 16) & 0xFF) . 
                       chr(($length >> 8) & 0xFF) . 
                       chr($length & 0xFF);
        }

        return $retval . $value;            
    }

    protected static function getExceptionMessage(string $detailedMessage, RenderingContextInterface $renderingContext): string
    {
        /** @var RenderingContext $renderingContext */
        $request = $renderingContext->getRequest();
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
