<?php

namespace image\gd;

use image\AbstractDecoder;
use image\exceptions\NotReadableException;
use image\exceptions\NotSupportedException;

class Decoder extends AbstractDecoder
{

    /**
     * @inheritDoc
     */
    public function loadFromPath($path)
    {
        if (!is_readable($path)) {
            throw new NotReadableException(
                "File '{$path}' is not exists or not readable."
            );
        }

        // exif_imagetype() is much faster than getimagesize()
        if (function_exists('exif_imagetype')) {
            $type = @exif_imagetype($path);
            // @todo может не надо исключение?..
            if (!$type) {
                throw new NotReadableException("Unable to read image from path '{$path}'.");
            }
        } else {
            list($width, $height, $type) = getimagesize($path);
            if (empty($width) && empty($height) && empty($type)) {
                throw new NotReadableException("Unable to read image from path '{$path}'.");
            }
        }

        $result = $this->getFileInfoFromPath($path);

        // These types will be automatically detected if your build of PHP supports them: JPEG, PNG, GIF, BMP, WBMP, GD2, and WEBP.
        $data = @imagecreatefromstring(file_get_contents($path));
        if (!empty($data)) {
            $this->gdResourceToTruecolor($data);

            // build image
            $res = $this->loadFromGd($data);
            $result['data'] = $res['data'];

            return $result;
        }

        $found = false;
        switch ($type) {
            case IMAGETYPE_GIF:
                $data = @imagecreatefromgif($path);
                $found = true;
                break;

            case IMAGETYPE_JPEG:
                $data = @imagecreatefromjpeg($path);
                $found = true;
                break;

            case IMAGETYPE_PNG:
                $data = @imagecreatefrompng($path);
                $found = true;
                break;

            case IMAGETYPE_WBMP:
                $data = @imagecreatefromwbmp($path);
                $found = true;
                break;

            case IMAGETYPE_XBM:
                $data = @imagecreatefromxbm($path);
                $found = true;
                break;
        }

        // BMP support added in PHP 7.2
        if (!$found && function_exists('imagecreatefrombmp') && defined('IMAGETYPE_BMP') && $type === IMAGETYPE_BMP) {
            $data = @imagecreatefrombmp($path);
            $found = true;
        }

        // AVIF support added in PHP 8.1
        if (!$found && function_exists('imagecreatefromavif') && defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF) {
            $data = @imagecreatefromavif($path);
            $found = true;
        }

        // constant IMAGETYPE_WEBP added in PHP 7.1
        // But imagecreatefromwebp() added in PHP 5.4
        if (
            !$found
            && (
                (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP)
                || $result['extension'] === 'webp'
            )
        ) {
            $data = @imagecreatefromwebp($path);
            $found = true;
        }

        if (empty(!$found)) {
            switch ($result['extension']) {
                // The GD and GD2 image formats are proprietary image formats of libgd.
                // They have to be regarded obsolete, and should only be used for development and testing purposes.
                // @see https://www.php.net/manual/en/function.imagecreatefromgd2
//                case 'gd2':
//                    $data = @imagecreatefromgd2($path);
//                    $found = true;
//                    break;
//
//                case 'gd':
//                    $data = @imagecreatefromgd($path);
//                    $found = true;
//                    break;

                case 'tga':
                case 'tpic':
                    // TGA support added in PHP 7.4
                    if (function_exists('imagecreatefromtga')) {
                        $data = imagecreatefromtga($path);
                        $found = true;
                    }

            }
        }

        if (empty($data)) {
            throw new NotReadableException("Unable to read image from path '{$path}'.");
        }

        $this->gdResourceToTruecolor($data);

        // build image
        $res = $this->loadFromGd($data);
        $result['data'] = $res['data'];

        return $result;
    }

    /**
     * Initiates new image from binary data
     * @param  string $data
     * @return array{
     *     data: resource|\GdImage,
     *     mime: string,
     *     orientation?: int
     * }
     */
    public function loadFromBinary($data)
    {
        $resource = @imagecreatefromstring($data);

        if ($resource === false) {
            throw new NotReadableException(
                "Unable to init from given binary data."
            );
        }

        $result = $this->loadFromGd($resource);
        $result['mime'] = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $data);

        $exif = null;
        if (!empty($result['mime'])) {
            $exif = @exif_read_data('data://' . $result['mime'] . ';base64,' . base64_encode($data));
        }
        if (!empty($exif) && !empty($exif['Orientation'])) {
            $result['orientation'] = $exif['Orientation'];
        }

        return $result;
    }

    /**
     * Initiates new image from GD resource
     * @param resource|\GdImage $resource
     * @return array{data: resource|\GdImage}
     */
    public function loadFromGd($resource)
    {
        return [
            'data' => $resource,
        ];
    }

    /**
     * Initiates new image from Imagick object
     * @param  \Imagick $object
     */
    public function loadFromImagick(\Imagick $object)
    {
        throw new NotSupportedException(
            'Gd driver is unable to init from Imagick object'
        );
    }

    /**
     * Transform GD resource into Truecolor version
     *
     * @param  resource|\GdImage $resource
     * @return bool
     */
    public function gdResourceToTruecolor(&$resource)
    {
        if (imageistruecolor($resource)) {
            return true;
        }

        // This function converts transparent background to black
        //return imagepalettetotruecolor($resource);

        $width = imagesx($resource);
        $height = imagesy($resource);

        // new canvas
        $canvas = imagecreatetruecolor($width, $height);

        // fill with transparent color
        imagealphablending($canvas, false);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        imagecolortransparent($canvas, $transparent);
        imagealphablending($canvas, true);

        // copy original
        imagecopy($canvas, $resource, 0, 0, 0, 0, $width, $height);
        imagedestroy($resource);

        $resource = $canvas;

        return true;
    }
}