<?php

namespace image\imagick;

use image\AbstractDecoder;
use image\exception\NotReadableException;
use image\exception\NotSupportedException;
use image\Format;

class Decoder extends AbstractDecoder
{
    /**
     * Initiates new image from path in filesystem
     * @param string $path
     * @return array{
     *     data: \Imagick,
     *     mime?: string,
     *     format: string,
     *     dirName: string,
     *     baseName: string,
     *     extension: string,
     *     fileName: string,
     *     orientation: string
     * }
     */
    public function loadFromPath($path)
    {
        if (!is_readable($path)) {
            throw new NotReadableException(
                "File '{$path}' is not exists or not readable."
            );
        }

        $core = new \Imagick;

        try {
            $core->setBackgroundColor(new \ImagickPixel('transparent'));

            // Imagick PHP extension does not work with relative paths on Windows
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $core->readImageBlob(file_get_contents($path));
            } else {
                $core->readImage($path);
            }
            $core->setImageType(\Imagick::IMGTYPE_TRUECOLORMATTE);

        } catch (\ImagickException $e) {
            throw new NotReadableException(
                "Unable to read image from path '{$path}'.",
                0,
                $e
            );
        }

        $result = $this->getFileInfoFromPath($path);

        // build image
        $res = $this->loadFromImagick($core);
        $result['format'] = $res['format'];
        $result['data'] = $res['data'];
        $result['orientation'] = $res['orientation'];

        return $result;
    }

    /**
     * Initiates new image from GD resource
     * @param resource|\GdImage $resource
     */
    public function loadFromGd($resource)
    {
        throw new NotSupportedException(
            'Imagick driver is unable to init from GD resource.'
        );
    }

    /**
     * Initiates new image from Imagick object
     * @param  \Imagick $object
     * @return array{
     *     data: \Imagick,
     *     fileName?: string,
     *     mime: string,
     *     format: string,
     *     orientation: string,
     * }
     */
    public function loadFromImagick(\Imagick $object)
    {
        $format = mb_strtolower($object->getImageFormat());
        // Currently animations are not supported.
        // So all images are turned into static
        if (
            in_array($format, [Format::GIF, Format::APNG, Format::WEBP, Format::AVIF])
            //&& $imagick->getNumberImages() > 1
            && count($object) > 1
        ) {
            $object = $this->removeAnimation($object);
        }

        return [
            'data' => $object,
            'fileName' => $object->getFilename(),
            'mime' => $object->getImageMimeType(),
            'format' => $format,
            'orientation' => $object->getImageOrientation(),
        ];
    }

    /**
     * Initiates new image from binary data
     * @param  string $binary
     * @return array{
     *     data: \Imagick,
     *     mime: string,
     *     format: string,
     *     orientation: string,
     * }
     */
    public function loadFromBinary($binary)
    {
        $core = new \Imagick;

        try {
            $core->setBackgroundColor(new \ImagickPixel('transparent'));
            $core->readImageBlob($binary);
            $core->setImageType(\Imagick::IMGTYPE_TRUECOLORMATTE);

        } catch (\ImagickException $e) {
            throw new NotReadableException(
                'Unable to read image from binary data.',
                0,
                $e
            );
        }

        $result = $this->loadFromImagick($core);
        $result['mime'] = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $binary);

        return $result;
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