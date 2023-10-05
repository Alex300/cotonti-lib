<?php

namespace image\gd;

use image\AbstractImage;
use image\exceptions\InvalidArgumentException;
use image\exceptions\RuntimeException;

/**
 * Imagine implementation using the GD library.
 * @method \GdImage|resource|null getData()
  */
class Image extends AbstractImage
{
    /**
     * Image resource/object
     * @var \GdImage|resource|null
     */
    protected $data;

    /**
     * @var array|null
     */
    protected static $supportedFormats = null;

    /**
     * Checks if image processor module is installed and available
     * @return bool
     */
    public function isAvailable()
    {
        //return false;
        return extension_loaded('gd') && function_exists('gd_info');
    }

    public function destroy()
    {
        if ($this->data) {
            if (
                (is_resource($this->data) && get_resource_type($this->data) === 'gd')
                || $this->data instanceof \GdImage
            ) {
                imagedestroy($this->data);
            }
            $this->data = null;
        }
    }

    /**
     * @return \GdImage|resource|null
     */
    public function cloneData($data = null)
    {
        if ($data === null) {
            $data = $this->data;
        }

        if ($data === null) {
            return null;
        }

        $width = imagesx($data);
        $height = imagesy($data);
        $clone = imagecreatetruecolor($width, $height);
        imagealphablending($clone, false);
        imagesavealpha($clone, true);
        $transparency = imagecolorallocatealpha($clone, 0, 0, 0, 127);
        imagefill($clone, 0, 0, $transparency);

        imagecopy($clone, $data, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    /**
     * @return Decoder
     */
    public function getDecoder()
    {
        return new Decoder();
    }

    /**
     * @return Encoder
     */
    public function getEncoder()
    {
        return new Encoder();
    }

    public function getSupportedFormats()
    {
        if (static::$supportedFormats !== null) {
            return static::$supportedFormats;
        }

        static::$supportedFormats = [];
        foreach (gd_info() as $name => $supported) {
            if (!$supported || mb_strpos($name, ' Support') === false) {
                continue;
            }
            $parts = explode(' ', $name);
            $format = mb_strtolower($parts[0]);
            if (
                in_array($parts[0], ['FreeType', 'JIS-mapped'])
                || in_array($format, static::$supportedFormats)
            ) {
                continue;
            }
            static::$supportedFormats[] = $format;
        }

        static::$supportedFormats = array_merge(static::$supportedFormats, ['jpg']);
        static::$supportedFormats = array_unique(static::$supportedFormats);
        sort(static::$supportedFormats);

        return static::$supportedFormats;
    }

    /**
     * @inheritDoc
     */
    protected function doCrop($width, $height, $x = null, $y = null)
    {
        $dest = $this->createImage($width, $height, 'crop');

        if (imagecopy($dest, $this->data, 0, 0, $x, $y, $width, $height) === false) {
            imagedestroy($dest);
            throw new RuntimeException('Image crop operation failed');
        }

        imagedestroy($this->data);

        $this->data = $dest;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function flipHorizontally()
    {
        imageflip($this->data, IMG_FLIP_HORIZONTAL);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function flipVertically()
    {
        imageflip($this->data, IMG_FLIP_VERTICAL);
        return $this;
    }

    /**
     * @inheritDoc
     * GD doesn't support exif data
     */
    protected function setExifOrientation($orientation)
    {
    }

    /**
     * @inheritDoc
     */
    public function getHeight()
    {
        return imagesy($this->data);
    }

    /**
     * @inheritDoc
     */
    public function getWidth()
    {
        return imagesx($this->data);
    }

    /**
     * @inheritDoc
     */
    public function paste(AbstractImage $image, $x, $y, $alpha = 100)
    {
        $x = (int) $x;
        $y = (int) $y;
        $alpha = (int) $alpha;

        if (!$image instanceof self) {
            throw new InvalidArgumentException(
                sprintf('%s can only paste() %s instances, %s given', self::class, self::class, get_class($image))
            );
        }

        if ($alpha < 0 || $alpha > 100) {
            throw new InvalidArgumentException(
                sprintf('The %1$s argument can range from %2$d to %3$d, but you specified %4$d.', '$alpha', 0, 100, $alpha)
            );
        }

        $imageWidth = $image->getWidth();
        $imageHeight = $image->getHeight();

        if ($alpha === 100) {
            imagealphablending($this->data, true);
            imagealphablending($image->getData(), true);

            $success = imagecopy($this->data, $image->getData(), $x, $y, 0, 0, $imageWidth, $imageHeight);

            imagealphablending($this->data, false);
            imagealphablending($image->getData(), false);

            if ($success === false) {
                throw new RuntimeException('Image paste operation failed');
            }
        } elseif ($alpha > 0) {
            if (!imagecopymerge($this->data, $image->getData(), $x, $y, 0, 0, $imageWidth, $imageHeight, $alpha)) {
                throw new RuntimeException('Image paste operation failed');
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function removeAnimation()
    {
    }

    /**
     * @inheritDoc
     * Please note that GD doesn't support different filters, so the $filter argument is ignored.
     */
    public function doResize($width = null, $height = null, $filter = \image\Image::FILTER_UNDEFINED)
    {
        $originalWidth = $this->getWidth();
        $originalHeight = $this->getHeight();
        $ratio = $originalWidth / $originalHeight;

        if ($width === 0) {
            $width = (int) ($height * $ratio);
        } elseif ($height === 0) {
            $height = (int) ($width / $ratio);
        }

        $dest = $this->createImage($width, $height, 'resize');

        imagealphablending($this->data, true);
        imagealphablending($dest, true);

        $success = imagecopyresampled($dest, $this->data, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);

        imagealphablending($this->data, false);
        imagealphablending($dest, false);

        if ($success === false) {
            imagedestroy($dest);
            throw new RuntimeException('Image resize operation failed');
        }

        imagedestroy($this->data);

        $this->data = $dest;

        return $this;
    }

    /**
     * @inheritDoc
     * @todo background support
     */
    public function rotate($angle, $background = null)
    {
//        if ($background === null) {
//            $background = $this->palette->color('fff');
//        }
//        $color = $this->getColor($background);

        // restrict rotations beyond 360 degrees, since the end result is the same
        $angle = fmod($angle, 360);

        $color = imagecolorallocatealpha($this->data, 255, 255, 255, 127);

        $resource = imagerotate($this->data, -1 * $angle, $color);

        if ($resource === false) {
            throw new RuntimeException('Image rotate operation failed');
        }

        imagedestroy($this->data);
        $this->data = $resource;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function strip()
    {
        // GD strips profiles and comment, so there's nothing to do here
        return $this;
    }

    /**
     * Generates a GD image.
     * @param int $width
     * @param int $height
     * @param string $operation The operation initiating the creation
     * @return resource|\GdImage
     */
    private function createImage($width, $height, $operation = '')
    {
        $width = (int) $width;
        $height = (int) $height;

        $operation = $operation !== '' ? $operation : 'create';

        $resource = imagecreatetruecolor($width, $height);

        if ($resource === false) {
            throw new RuntimeException('Image ' . $operation . ' failed');
        }

        if (imagealphablending($resource, false) === false || imagesavealpha($resource, true) === false) {
            throw new RuntimeException('Image ' . $operation . ' failed');
        }

        if (function_exists('imageantialias')) {
            imageantialias($resource, true);
        }

        $transparent = imagecolorallocatealpha($resource, 255, 255, 255, 127);
        imagefill($resource, 0, 0, $transparent);
        imagecolortransparent($resource, $transparent);

        return $resource;
    }
}