<?php

namespace image\gd;

use image\AbstractEncoder;
use image\exceptions\NotSupportedException;
use image\Format;

class Encoder extends AbstractEncoder
{
    /**
     * @inheritDoc
     */
    protected function process($fileName = null)
    {
        if ($fileName === null) {
            ob_start();
            ob_implicit_flush(false);
        }

        $result = false;
        $notSupportedMessage = '';

        switch ($this->format) {
            case Format::AVIF:
                // Todo test $quality
                if (function_exists('imageavif')) {
                    $resource = $this->image->getData();
                    imagealphablending($resource, true);
                    imagesavealpha($resource, true);
                    $result = imageavif($resource, $fileName, $this->quality);
                } else {
                    $notSupportedMessage = 'AVIF support was added in PHP 8.1';
                }
                break;

            case Format::BMP:
                if (function_exists('imagebmp')) {
                    $resource = $this->image->getData();
                    imagealphablending($resource, false);
                    imagesavealpha($resource, true);
                    $result = imagebmp($resource, $fileName);
                } else {
                    $notSupportedMessage = 'AVIF support was added in PHP 8.1';
                }
                break;
            // The GD and GD2 image formats are proprietary image formats of libgd.
            // They have to be regarded obsolete, and should only be used for development and testing purposes.
            // @see https://www.php.net/manual/en/function.imagegd2
//            case Format::GD2:
//                $result = imagegd2($this->image->getData(), $fileName);
//                break;
//
//            case Format::GD:
//                $result = imagegd($this->image->getData(), $fileName);
//                break;

            case Format::GIF:
                if (!\image\Image::config('multipleSave')) {
                    $resource = $this->image->getData();
                } else {
                    $resource = $this->image->cloneData();
                }
                imagealphablending($resource, false);

                // Add transparency to image. Gif is a palette-based format and doesn't support full alpha transparency.
                $transparent = imagecolortransparent($resource, imagecolorallocate($resource, 0, 0, 0));
                $width = imagesx($resource);
                $height = imagesy($resource);
                for ($x = 0; $x < $width; $x++) {
                    for ($y = 0; $y < $height; $y++) {
                        $pixel = imagecolorsforindex($resource, imagecolorat($resource, $x, $y));
                        if ($pixel['alpha'] >= 64) {
                            imagesetpixel($resource, $x, $y, $transparent);
                        }
                    }
                }
                // /Add transparency to image.

                $result = imagegif($resource, $fileName);

                if (\image\Image::config('multipleSave')) {
                    imagedestroy($resource);
                }
                break;

            case Format::JPEG:
                $result = imagejpeg($this->image->getData(), $fileName, $this->quality);
                break;

            case Format::PNG:
                $resource = $this->image->getData();
                imagealphablending($resource, false);
                imagesavealpha($resource, true);
                $quality = -1;
                if (!empty($this->quality)) {
                    if ($this->quality > 9) {
                        $quality = (int) floor($this->quality / 10);
                    } else {
                        $quality = (int) $this->quality;
                    }
                }
                $result = imagepng($resource, $fileName, $quality);
                break;

            case Format::WBMP:
                $result = imagewbmp($this->image->getData(), $fileName);
                break;

            case Format::WEBP:
                $resource = $this->image->getData();
                imagealphablending($resource, false);
                imagesavealpha($resource, true);
                $quality = -1;
                if ($this->quality !== null && $this->quality >= 0 && $this->quality <= 100) {
                    $quality = $this->quality;
                }
                $result =  imagewebp($this->image->getData(), $fileName, $quality);
                break;

            case Format::XBM:
                $result = imagexbm($this->image->getData(), $fileName);
                break;
        }

        if ($result === false || $notSupportedMessage !== '') {
            throw new NotSupportedException(
                sprintf(
                    'Saving image in "%s" format is not supported, please use one of the following extensions: "%s"',
                    $this->format,
                    implode('", "', $this->image->getSupportedFormats())
                ) . $notSupportedMessage
            );
        }


        if ($fileName === null) {
            return ob_get_clean();
        }

        return $result;
    }
}