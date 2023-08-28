<?php

namespace image\imagick;

use image\AbstractEncoder;
use image\Format;

class Encoder extends AbstractEncoder
{
//    protected function saveOriginalImageParams()
//    {
//        /** @var \Imagick $imagick */
//        $imagick = $this->image->getData();
//        $this->imageOriginalParams = [
//            'imageBackgroundColor' => $imagick->getImageBackgroundColor(),
//            //'backgroundColor' => $imagick->getBackgroundColor(),
//            'format' => $imagick->getFormat(),
//            'imageFormat' => $imagick->getImageFormat(),
//            'compression' => $imagick->getCompression(),
//            'compressionQuality' => $imagick->getCompressionQuality(),
//            'imageCompression' => $imagick->getImageCompression(),
//            'imageCompressionQuality' => $imagick->getImageCompressionQuality(),
//        ];
//    }
//
//    protected function restoreOriginalImageParams()
//    {
//        /** @var \Imagick $imagick */
//        $imagick = $this->image->getData();
//
//        $imagick->setImageBackgroundColor($this->imageOriginalParams['imageBackgroundColor']);
//        $imagick->setFormat($this->imageOriginalParams['format']);
//        $imagick->setImageFormat($this->imageOriginalParams['imageFormat']);
//        $imagick->setCompression($this->imageOriginalParams['compression']);
//        $imagick->setImageCompression($this->imageOriginalParams['imageCompression']);
//        $imagick->setCompressionQuality($this->imageOriginalParams['compressionQuality']);
//        $imagick->setImageCompressionQuality($this->imageOriginalParams['imageCompressionQuality']);
//    }

    /**
     * @inheritDoc
     */
    protected function process($fileName = null)
    {
        // Prepare image to encoding
        /** @var \Imagick $imagick */
        if (\image\Image::config('multipleSave')) {
            $imagick = clone $this->image->getData();
            $destroyRecourse = true;
        } else {
            //$imagick = clone $this->image->getData();
            $imagick = $this->image->getData();
            $destroyRecourse = false;
        }
        // Animation is not supported at this moment
//        if (
//            !in_array($this->format, [Format::GIF, Format::APNG, Format::WEBP, Format::AVIF])
//            //&& $imagick->getNumberImages() > 1
//            && count($imagick) > 1
//        ) {
//            $imagick = $this->removeAnimation($imagick);
//            $destroyRecourse = true;
//        }

        // More formats
        if (in_array($this->format, [Format::JPEG])) {
            $imagick->setImageBackgroundColor('white');
            $imagick->setBackgroundColor('white');

            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }

        if (
            !in_array($this->format, [Format::PDF, Format::PSD, Format::TIFF])
            && $imagick->getNumberImages() > 1
        ) {
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_MERGE);
            // Imagick::mergeImageLayers() returns a new Imagick object containing the merged image.
            $destroyRecourse = true;
        }

        $imagick->setFormat($this->format);
        $imagick->setImageFormat($this->format);

        $compression = null;
        switch ($this->format) {
            case Format::JPEG:
                $compression = \Imagick::COMPRESSION_JPEG;
                break;

            case Format::PNG:
                $compression = \Imagick::COMPRESSION_ZIP;
                break;

            case Format::GIF:
                $compression = \Imagick::COMPRESSION_LZW;
                break;

            case Format::WEBP:
                $compression = \Imagick::COMPRESSION_JPEG;
                break;

            case Format::TIFF:
            case Format::BMP:
            case Format::ICO:
            case Format::PSD:
            case Format::AVIF:
            case Format::HEIC:
                $compression = \Imagick::COMPRESSION_UNDEFINED;
                break;
        }
        if ($compression !== null) {
            $imagick->setCompression($compression);
            $imagick->setImageCompression($compression);
        }

        if ($this->quality !== null) {
            $imagick->setCompressionQuality($this->quality);
            $imagick->setImageCompressionQuality($this->quality);
        }
        // /Prepare image to encoding

        if ($destroyRecourse) {
            $result = $this->getOutput($imagick, $fileName);
            $imagick->clear();
            return $result;
        }

        return $this->getOutput($imagick, $fileName);
        //$this->restoreOriginalImageParams();
    }


    /**
     * @param \Imagick $imagick
     * @param ?string $fileName File name to save encoded image. If is null - encoded image will be returned as string
     * @return string|bool
     */
    protected function getOutput(\Imagick $imagick, $fileName = null)
    {
        if ($fileName === null) {
            return $imagick->getImagesBlob();
        }

        // Imagick PHP extension does not work with relative paths on Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return (bool) @file_put_contents($fileName, $imagick->getImagesBlob());
        }

        return $imagick->writeImage($fileName);
    }

    /**
     * Turns object into one frame Imagick object by removing all frames except first
     * @param \Imagick $object
     * @return \Imagick
     */
    private function removeAnimation(\Imagick $object)
    {
        $imagick = new \Imagick;

        foreach ($object as $frame) {
            $imagick->addImage($frame->getImage());
            break;
        }

        $object->clear();
        $object->destroy();

        return $imagick;
    }
}