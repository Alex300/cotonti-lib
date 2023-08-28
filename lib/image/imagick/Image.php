<?php

namespace image\imagick;

use image\AbstractImage;
use image\exception\InvalidArgumentException;
use image\exception\RuntimeException;

/**
 * Image implementation using the Imagick PHP extension.
 *
 * @method \Imagick|null getData()
 * @method \Imagick|null cloneData()
 */
class Image extends AbstractImage
{
    protected static $filterMap = [
        \image\Image::FILTER_UNDEFINED => \Imagick::FILTER_UNDEFINED,
        \image\Image::FILTER_POINT => \Imagick::FILTER_POINT,
        \image\Image::FILTER_BOX => \Imagick::FILTER_BOX,
        \image\Image::FILTER_TRIANGLE => \Imagick::FILTER_TRIANGLE,
        \image\Image::FILTER_HERMITE => \Imagick::FILTER_HERMITE,
        \image\Image::FILTER_HANNING => \Imagick::FILTER_HANNING,
        \image\Image::FILTER_HAMMING => \Imagick::FILTER_HAMMING,
        \image\Image::FILTER_BLACKMAN => \Imagick::FILTER_BLACKMAN,
        \image\Image::FILTER_GAUSSIAN => \Imagick::FILTER_GAUSSIAN,
        \image\Image::FILTER_QUADRATIC => \Imagick::FILTER_QUADRATIC,
        \image\Image::FILTER_CUBIC => \Imagick::FILTER_CUBIC,
        \image\Image::FILTER_CATROM => \Imagick::FILTER_CATROM,
        \image\Image::FILTER_MITCHELL => \Imagick::FILTER_MITCHELL,
        \image\Image::FILTER_LANCZOS => \Imagick::FILTER_LANCZOS,
        \image\Image::FILTER_BESSEL => \Imagick::FILTER_BESSEL,
        \image\Image::FILTER_SINC => \Imagick::FILTER_SINC,
    ];

    /**
     * Image object
     * @var \Imagick|null
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
        return extension_loaded('imagick') && class_exists('Imagick') && !empty(\Imagick::queryFormats());
    }

    public function destroy()
    {
        if ($this->data instanceof \Imagick) {
            $this->data->clear();
            $this->data->destroy();
        }
        $this->data = null;
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

        static::$supportedFormats = \Imagick::queryformats();
        if (empty(static::$supportedFormats)) {
            static::$supportedFormats = [];
            return static::$supportedFormats;
        }

        static::$supportedFormats = array_map('mb_strtolower', static::$supportedFormats);

        return static::$supportedFormats;
    }

    /**
     * @inheritDoc
     */
    protected function doCrop($width, $height, $x = null, $y = null)
    {
        try {
            if (count($this->data) > 1) {
                // Crop each layer separately
                $this->data = $this->data->coalesceImages();
                foreach ($this->data as $frame) {
                    $frame->cropImage($width, $height, $x, $y);
                    // Reset canvas for gif format
                    $frame->setImagePage(0, 0, 0, 0);
                }
                $this->data = $this->data->deconstructImages();
            } else {
                $this->data->cropImage($width, $height, $x, $y);
                // Reset canvas for gif format
                $this->data->setImagePage(0, 0, 0, 0);
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Crop operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function flipHorizontally()
    {
        try {
            $this->data->flopImage();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Horizontal Flip operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function flipVertically()
    {
        try {
            $this->data->flipImage();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Vertical flip operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function setExifOrientation($orientation)
    {
        try {
            $this->data->setImageOrientation($orientation);
//        if (!empty($this->data->getImageProperty('Exif:Thumbnail:Orientation'))) {
//            $this->data->setImageProperty('Exif:Thumbnail:Orientation', $orientation);
//        }
//        if (!empty($this->data->getImageProperty('Exif:Orientation'))) {
//            $this->data->setImageProperty('Exif:Orientation', $orientation);
//        }
        } catch (\ImagickException $e) {
            throw new RuntimeException('set Exif orientation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getHeight()
    {
        try {
            return $this->data->getImageHeight();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Get image height operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getWidth()
    {
        try {
            return $this->data->getImageWidth();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Get image width operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
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

        $pasteImage = null;
        if ($alpha === 100) {
            $pasteImage = $image->getData();
        } elseif ($alpha > 0) {
            $pasteImage = $image->cloneData();
            // setImageOpacity was replaced with setImageAlpha in php-imagick v3.4.3
            if (method_exists($pasteImage, 'setImageAlpha')) {
                $pasteImage->setImageAlpha($alpha / 100);
            } else {
                $pasteImage->setImageOpacity($alpha / 100);
            }
        }

        if ($pasteImage === null) {
            return $this;
        }

        try {
            $this->data->compositeImage($pasteImage, \Imagick::COMPOSITE_DEFAULT, $x, $y);
            $error = null;
        } catch (\ImagickException $e) {
            $error = $e;
        }
        if ($pasteImage !== $image->getData()) {
            $pasteImage->clear();
            $pasteImage->destroy();
        }
        if ($error !== null) {
            throw new RuntimeException('Paste operation failed: ' . $e->getMessage(), $error->getCode(), $error);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function removeAnimation()
    {
        if (count($this->data) < 2) {
            return $this;
        }

        $imagick = new \Imagick;
        foreach ($this->data as $frame) {
            $imagick->addImage($frame->getImage());
            break;
        }

        $this->data->clear();
        $this->data->destroy();

        $this->data = $imagick;

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function doResize($width = null, $height = null, $filter = \image\Image::FILTER_UNDEFINED)
    {
        if (isset(static::$filterMap[$filter])) {
            $filter = static::$filterMap[$filter];
//        } elseif (!in_array($filter, static::$filterMap)) {
//            throw new InvalidArgumentException('Unsupported filter type');
        }

        try {
            if (count($this->data) > 1) {
                $this->data = $this->data->coalesceImages();
                foreach ($this->data as $frame) {
                    $frame->resizeImage($width, $height, $filter, 1);
                }
                $this->data = $this->data->deconstructImages();
            } else {
                $this->data->resizeImage($width, $height, $filter, 1);
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Resize operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * @inheritDoc
     * @todo background support
     */
    public function rotate($angle, $background = null)
    {
        if ($background === null) {
            //$background = $this->palette->color('fff');
            $background = '#fff';
        }

        // restrict rotations beyond 360 degrees, since the end result is the same
        $angle = fmod($angle, 360);

        try {
            $pixel = new \ImagickPixel($background);
            $pixel->setColorValue(\Imagick::COLOR_ALPHA, 0);

            $this->data->rotateimage($pixel, $angle);
            $this->data->setImagePage(0, 0, 0, 0);

            $pixel->clear();
            $pixel->destroy();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Rotate operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function strip()
    {
        try {
            /**
             * StripImage also delete ICC image profile by default.
             * The resulting images seem to lose a lot of color information and look "flat" compared to their non-stripped versions.
             * Consider keeping the ICC profile (which causes richer colors) while removing all other EXIF data:
             */
            $profiles = $this->data->getImageProfiles('icc', true);
            $this->data->stripImage();
            if (!empty($profiles)) {
                $this->data->profileImage("icc", $profiles['icc']);
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Strip operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return $this;
    }
}