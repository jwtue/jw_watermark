<?php

namespace Jw301\Watermark\ViewHelpers;

use TYPO3\CMS\Fluid\ViewHelpers\Uri\ImageViewHelper;

use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Fluid\Core\ViewHelper\Exception;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;
use TYPO3\CMS\Core\Utility\MathUtility;

class WatermarkViewHelper extends \TYPO3\CMS\Fluid\ViewHelpers\Uri\ImageViewHelper
{
    /**
     * Initialize arguments
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
		
        $this->registerArgument('watermarkSrc', 'string', 'watermarkSrc');
        $this->registerArgument('watermarkTreatIdAsReference', 'bool', 'given watermarkSrc argument is a sys_file_reference record', false, false);
        $this->registerArgument('watermarkImage', 'object', 'watermarkImage');
        $this->registerArgument('watermarkOpacity', 'string', 'opacity value for the watermark image (0=fully transparent, 1=fully opaque)');
        $this->registerArgument('watermarkBackgroundColor', 'string', 'hex code for the background color of the watermark');
        $this->registerArgument('watermarkBackgroundOpacity', 'string', 'opacity value for the background color (0=fully transparent, 1=fully opaque)');
        $this->registerArgument('watermarkOffset', 'pixels', 'offset from the edge in pixels');
        $this->registerArgument('watermarkPositionHorizontal', 'string', 'horizontal position in the image (left/center/right)');
        $this->registerArgument('watermarkPositionVertical', 'string', 'vertical position in the image (top/middle/bottom)');
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
    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext)
    {		
	    $src = $arguments['src'];
        $image = $arguments['image'];
        $treatIdAsReference = $arguments['treatIdAsReference'];
        $cropString = $arguments['crop'];
        $absolute = $arguments['absolute'];
				
        $watermarkSrc = $arguments['watermarkSrc'];
        $watermarkImage = $arguments['watermarkImage'];
        $watermarkTreatIdAsReference = $arguments['watermarkTreatIdAsReference'];

        if ((is_null($src) && is_null($image)) || (!is_null($src) && !is_null($image))) {
            throw new Exception('You must either specify a string src or a File object.', 1460976233);
        }

        try {
            $imageService = self::getImageService();
            $image = $imageService->getImage($src, $image, $treatIdAsReference);

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
				'watermarkPositionHorizontal' => $arguments['watermarkPositionHorizontal'],
				'watermarkPositionVertical' => $arguments['watermarkPositionVertical']
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
					
					$watermarkOpacityBitmap = imagecreatetruecolor(imagesx($watermarkBitmap), imagesy($watermarkBitmap));
					imagealphablending($watermarkOpacityBitmap, true);
					imagesavealpha($watermarkOpacityBitmap, true);
					imagefill($watermarkOpacityBitmap, 0, 0, imagecolorallocatealpha($watermarkOpacityBitmap, 0, 0, 0, 127));
					
					$opacity = $arguments['watermarkOpacity'] ?: 1;
									
					for ($x = 0; $x < imagesx($watermarkBitmap); $x++) {
						for ($y = 0; $y < imagesy($watermarkBitmap); $y++) {
							$color = imagecolorat($watermarkBitmap, $x, $y);
																
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
					
					$topleftx = $offset;
					$toplefty = $offset;
					
					if ($arguments['watermarkPositionHorizontal']) {
						if ($arguments['watermarkPositionHorizontal'] == "center") {
							$topleftx = (imagesx($imageBitmap)/2)-(imagesx($watermarkBitmap)/2);
						} else if ($arguments['watermarkPositionHorizontal'] == "right") {
							$topleftx = imagesx($imageBitmap)-imagesx($watermarkBitmap)-$offset;
						}
					}
					if ($arguments['watermarkPositionVertical']) {
						if ($arguments['watermarkPositionVertical'] == "middle") {
							$toplefty = (imagesy($imageBitmap)/2)-(imagesy($watermarkBitmap)/2);
						} else if ($arguments['watermarkPositionVertical'] == "bottom") {
							$toplefty = imagesy($imageBitmap)-imagesy($watermarkBitmap)-$offset;
						}
					}
								
					$backgroundcolor = array(hexdec(substr($backgroundcolorhex, 0, 2)), hexdec(substr($backgroundcolorhex, 2, 2)), hexdec(substr($backgroundcolorhex, 4, 2)));
					
					$bgcolor = imagecolorallocatealpha($imageBitmap, $backgroundcolor[0], $backgroundcolor[1], $backgroundcolor[2], $backgroundcoloralpha);
					imagefilledrectangle($imageBitmap, $topleftx, $toplefty, $topleftx+imagesx($watermarkBitmap), $toplefty+imagesy($watermarkBitmap), $bgcolor);
					
					imagecopy($imageBitmap, $watermarkOpacityBitmap, $topleftx, $toplefty, 0, 0, imagesx($watermarkBitmap), imagesy($watermarkBitmap));
					
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
				} catch (\UnexpectedValueException $e) {
				} catch (\RuntimeException $e) {
				} catch (\InvalidArgumentException $e) {
				}			
			}

            return $imageService->getImageUri($processedImage, $absolute);
        } catch (ResourceDoesNotExistException $e) {
            // thrown if file does not exist
        } catch (\UnexpectedValueException $e) {
            // thrown if a file has been replaced with a folder
        } catch (\RuntimeException $e) {
            // RuntimeException thrown if a file is outside of a storage
        } catch (\InvalidArgumentException $e) {
            // thrown if file storage does not exist
        }
        return '';
    }
	const IPTC_ENCODING = "1#090";
	const IPTC_COPYRIGHT_STRING = "2#116";

	const IPTC_ENCODING_UTF8 = "\x1B%G";
	
	private static function setCopyrightString($file, $string) {
		
		$mode = 0;
		$meta = array(
			self::IPTC_ENCODING 		=> self::IPTC_ENCODING_UTF8,
			self::IPTC_COPYRIGHT_STRING => utf8_decode($string)
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

		return isset($meta[self::IPTC_COPYRIGHT_STRING]) ? utf8_encode($meta[self::IPTC_COPYRIGHT_STRING][0]) : null;
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
}
