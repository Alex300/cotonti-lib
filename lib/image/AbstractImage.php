<?php

namespace image;

use image\exception\InvalidArgumentException;
use image\exception\NotWritableException;

/**
 * @todo Gamma correction, filters,
 * @todo Color support
 */
abstract class AbstractImage
{
    /**
     * Mime type
     * @var ?string
     */
    protected $mime;

    /**
     * Image format
     * @var string
     */
    protected $format;

    /**
     * Name of directory path
     * @var ?string
     */
    protected $dirName;

    /**
     * Basename of current file
     * @var ?string
     */
    protected $baseName;

    /**
     * File extension of current file
     * @var ?string
     */
    protected $extension;

    /**
     * File name of current file
     * @var ?string
     */
    protected $fileName;

    /**
     * Image orientation from EXIF
     * @var ?int
     */
    protected $orientation;

    /**
     * Image resource/object of current image processor
     * @var \Imagick|\GdImage|resource|null
     */
    protected $data;

    protected static $filterMap = [];

    public function __destruct()
    {
        $this->destroy();
    }

    public function __clone()
    {
        if ($this->data !== null) {
            $this->data = $this->cloneData();
        }
    }

    /**
     * Checks if image processor module is installed and available
     * @return bool
     */
    public abstract function isAvailable();

    public abstract function destroy();

    /**
     * @return \Imagick|\GdImage|resource|null
     */
    public function cloneData($data = null)
    {
        if ($data === null) {
            $data = $this->data;
        }

        if ($data !== null) {
            return clone $data;
        }
        return null;
    }

    /**
     * @return AbstractDecoder
     */
    public abstract function getDecoder();

    /**
     * @return AbstractEncoder
     */
    public abstract function getEncoder();


    /**
     * @return string[]
     */
    public abstract function getSupportedFormats();

    /**
     * Image resource/object of current image processor
     * @return \Imagick|\GdImage|resource|null
     */
    public function getData()
    {
        return $this->data;
    }

    public function getMime()
    {
        return $this->mime;
    }

    public function getFormat()
    {
        return $this->format;
    }

    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * @param string $path
     * @return self
     */
    public function load($path)
    {
        $result = $this->getDecoder()->load($path);
        $this->data = $result['data'];
        $this->fileName = !empty($result['fileName']) ? $result['fileName'] : null;
        $this->mime = !empty($result['mime']) ? $result['mime'] : null;
        $this->baseName = !empty($result['baseName']) ? $result['baseName'] : null;
        $this->dirName = !empty($result['dirName']) ? $result['dirName'] : null;
        $this->extension = !empty($result['extension']) ? $result['extension'] : null;
        $this->format = !empty($result['extension']) ? $result['extension'] : null;
        $this->orientation = !empty($result['orientation']) ? $result['orientation'] : null;

        return $this;
    }

    /**
     * Saves encoded image in filesystem
     * @param string $fileName
     * @param int $quality
     * @param string $format
     * @return self
     */
    public function save($fileName = null, $quality = null, $format = null)
    {
        $fileName = $fileName === null ? $this->fileFullName() : $fileName;
        if (is_null($fileName)) {
            throw new NotWritableException(
                "Can't write to undefined path."
            );
        }

        if ($format === null) {
            $format = pathinfo($fileName, PATHINFO_EXTENSION);
        }

        $saved = $this->encode($format, $quality, $fileName);

//        $data = $this->encode($format, $quality, $fileName);
//        $saved = @file_put_contents($fileName, $data);
        if ($saved === false) {
            throw new NotWritableException(
                "Can't write image data to path '{$fileName}'"
            );
        }

        // set new file info Зачем?
        //$this->setFileInfoFromPath($path);

        return $this;
    }

    /**
     * Encodes given image
     * @param string $format
     * @param int $quality
     * @param ?string $fileName File name to save encoded image. If is null - encoded image will be returned as string
     * @return string|bool If $fileName is passed it will return bool (success file saved or not),
     *    otherwise it returns encoded image as string
     */
    public function encode($format, $quality, $fileName = null)
    {
        return $this->getEncoder()->encode($this, $fileName, $format, $quality);
    }

    /**
     * Get fully qualified path
     * @return string
     */
    public function fileFullName()
    {
        if (!empty($this->dirName) && !empty($this->baseName)) {
            return $this->dirName .'/'. $this->baseName;
        }

        return null;
    }

    /**
     * Crops a specified box out of the source image (modifies the source image)
     * Returns cropped self.
     * @param int $width
     * @param int $height
     * @param ?int $x The X coordinate of the cropped region's top left corner
     * @param ?int $y The Y coordinate of the cropped region's top left corner
     * @return self
     *
     * @todo validate arguments
     */
    public function crop($width, $height, $x = null, $y = null)
    {
        $originalWidth = $this->getWidth();
        $originalHeight = $this->getHeight();

        $width = (int) $width;
        $height = (int) $height;
        $x = ($x !== null)
            ? (int) $x
            : max(0, round(($originalWidth - $width) / 2));
        $y = ($y !== null)
            ? (int) $y
            : max(0, round(($originalHeight - $height) / 2));


//        if (!$start->in($this->getSize())) {
//            throw new OutOfBoundsException('Crop coordinates must start at minimum 0, 0 position from top left corner, crop height and width must be positive integers and must not exceed the current image borders');
//        }

        if ($originalWidth === $width && $originalHeight === $height) {
            // The image size is the same as the wanted size.
            return $this;
        }

        $this->doCrop($width, $height, $x, $y);

        return $this;

    }

    protected abstract function doCrop($width, $height, $x = null, $y = null);

    /**
     * Rotates the image according to the EXIF orientation data
     * @return self
     */
    public function fixOrientation()
    {
        if (empty($this->orientation)) {
            return $this;
        }
        $orientationChanged = true;
        // NOTE: Values 2, 4, 5, 7 are uncommon since they represent "flipped" orientations.
        switch ($this->orientation) {
            // horizontal flip
            case Image::ORIENTATION_TOPRIGHT:
                $this->flipHorizontally();
                break;

            // 180 rotate left
            case Image::ORIENTATION_BOTTOMRIGHT:
                $this->rotate(180);
                break;

            // vertical flip
            case Image::ORIENTATION_BOTTOMLEFT:
                $this->flipVertically();
                break;

            // vertical flip + 90 rotate right
            case Image::ORIENTATION_LEFTTOP:
                $this->flipVertically();
                $this->rotate(90);
                break;

            // 90 rotate right
            case Image::ORIENTATION_RIGHTTOP:
                $this->rotate(90);
                break;

            // horizontal flip + 90 rotate right
            case Image::ORIENTATION_RIGHTBOTTOM:
                $this->flipHorizontally();
                $this->rotate(90);
                break;

            // 90 rotate left
            case Image::ORIENTATION_LEFTBOTTOM:
                $this->rotate(-90);
                break;

            default:
                $orientationChanged = false;
        }

        if ($orientationChanged) {
            $this->orientation = Image::ORIENTATION_TOPLEFT;
            $this->setExifOrientation(Image::ORIENTATION_TOPLEFT);
        }
        return $this;
    }

    /**
     * @return void
     */
    protected abstract function setExifOrientation($orientation);

    /**
     * Flips current image using vertical axis. (Mirrors an image)
     * @return self
     */
    public abstract function flipHorizontally();

    /**
     * Flips current image using horizontal axis. (Mirrors an image)
     * @return self
     */
    public abstract function flipVertically();

    /**
     * @return int
     */
    public abstract function getHeight();

    /**
     * @return int
     */
    public function getOrientation()
    {
        return $this->orientation;
    }

    /**
     * @return int
     */
    public abstract function getWidth();

    /**
     * Pastes an image into a parent image
     * Returns source image
     *
     * @param AbstractImage $image
     * @param int $x The X coordinate of the upper left corner of the inserted image
     * @param int $y The Y coordinate of the upper left corner of the inserted image
     * @param int $alpha How to paste the image, from 0 (fully transparent) to 100 (fully opaque)
     * @return self
     */
    public abstract function paste(AbstractImage $image, $x, $y, $alpha = 100);

    /**
     * Turns object into one frame Imagick object by removing all frames except first
     * @return self
     */
    public abstract function removeAnimation();

    /**
     * Resizes current image and returns self.
     * For proportional resizing set width or height to NULL
     * @param int|string $width Absolute (int) or percent (string: '10%') value. IF NULL it will be calculated proportionally
     * @param int|string $height Absolute (int) or percent (string: '80%') value. IF NULL it will be calculated proportionally
     * @param string $filter
     * @return self
     */
    public function resize($width = null, $height = null, $filter = Image::FILTER_UNDEFINED)
    {
        $originalWidth = $this->getWidth();
        $originalHeight = $this->getHeight();

        $width = (!empty($width) && mb_substr($width, -1, 1) == '%')
            ? (int) ($originalWidth * (int) mb_substr($width, 0, -1) / 100)
            : (int) $width;

        $height = (!empty($height) && mb_substr($height, -1, 1) == '%')
            ? (int) ($originalHeight * (int) mb_substr($height, 0, -1) / 100)
            : (int) $height;

        if ($width === 0 && $height === 0) {
            throw new InvalidArgumentException('Width or height needs to be defined.');
        }

        if ($originalWidth === $width && $originalHeight === $height) {
            // The image size is the same as the wanted size.
            return $this;
        }

        $this->doResize($width, $height, $filter);

        return $this;
    }

    protected abstract function doResize($width = null, $height = null, $filter = Image::FILTER_UNDEFINED);

    /**
     * Rotates an image at the given angle.
     * Optional $background can be used to specify the fill color of the empty
     * area of rotated image.
     * @param int $angle
     * @param $background
     * @return self
     */
    public abstract function rotate($angle, $background = null);

    /**
     * Remove all exif data and comments.
     * @return self
     */
    public abstract function strip();

    /**
     * @param int|string $width Absolute (int) or percent (string: '10%') value. IF NULL it will be calculated proportionally
     * @param int|string $height Absolute (int) or percent (string: '80%') value. IF NULL it will be calculated proportionally
     * @param string $resize Resize mode
     * @param bool $upscale
     * @param string $filter
     * @return self
     */
    public function thumbnail($width, $height, $resize = Image::THUMBNAIL_OUTBOUND, $upscale = false, $filter = Image::FILTER_LANCZOS)
    {
        $imageWidth = $this->getWidth();
        $imageHeight = $this->getHeight();

        $width = (!empty($width) && mb_substr($width, -1, 1) == '%')
            ? (int) ($imageWidth * (int) mb_substr($width, 0, -1) / 100)
            : (int) $width;

        $height = (!empty($height) && mb_substr($height, -1, 1) == '%')
            ? (int) ($imageHeight * (int) mb_substr($height, 0, -1) / 100)
            : (int) $height;

        if ($width === 0 && $height === 0) {
            throw new InvalidArgumentException('Width or height needs to be defined.');
        }

        $thumbnail = $this;
        $thumbnail->strip();

        $ratios = [
            $width / $imageWidth,
            $height / $imageHeight,
        ];

        if ($resize === Image::THUMBNAIL_WIDTH || $height === 0) {
            $resize = Image::THUMBNAIL_WIDTH;
            $height = max(1, round($ratios[0] * $imageHeight));
        }

        if ($resize === Image::THUMBNAIL_HEIGHT || $width === 0) {
            $resize = Image::THUMBNAIL_HEIGHT;
            $width = max(1, round($ratios[1] * $imageWidth));
        }

        if ($imageWidth === $width && $imageHeight === $height) {
            // The image size is the same as the wanted size.
            return $thumbnail;
        }

        if (!$upscale && $imageWidth <= $width && $imageHeight <= $height) {
            // Do not upscale smaller images
            return $thumbnail;
        }

        switch ($resize) {
            case Image::THUMBNAIL_OUTBOUND:
                // Crop the image so that it fits the wanted size
                $ratio = max($ratios);
                if ($imageWidth >= $width && $imageHeight >= $height) {
                    // Downscale the image
                    $imageWidth = max(1, round($ratio * $imageWidth));
                    $imageHeight = max(1, round($ratio * $imageHeight));
                    $thumbnail->resize($imageWidth, $imageHeight, $filter);
                    $thumbnailWidth = $width;
                    $thumbnailHeight = $height;

                } else {
                    if ($upscale) {
                        // Upscale the image so that the max dimension will be the wanted one
                        $imageWidth = max(1, round($ratio * $imageWidth));
                        $imageHeight = max(1, round($ratio * $imageHeight));
                        $thumbnail->resize($imageWidth, $imageHeight, $filter);
                    }
                    $thumbnailWidth = min($imageWidth, $width);
                    $thumbnailHeight = min($imageHeight, $height);
                }

                $thumbnail->crop(
                    $thumbnailWidth,
                    $thumbnailHeight,
                    max(0, round(($imageWidth - $width) / 2)),
                    max(0, round(($imageHeight - $height) / 2))
                );
                return $thumbnail;
                break;

            case Image::THUMBNAIL_INSET:
                // Scale the image so that it fits the wanted size
                $ratio = min($ratios);
                $thumbnailWidth = max(1, round($ratio * $imageWidth));
                $thumbnailHeight = max(1, round($ratio * $imageHeight));
                $thumbnail->resize($thumbnailWidth, $thumbnailHeight, $filter);
                return $thumbnail;
                break;

            case Image::THUMBNAIL_WIDTH:
            case Image::THUMBNAIL_HEIGHT:
                $thumbnail->resize($width, $height, $filter);
                return $thumbnail;
                break;

            default:
                throw new InvalidArgumentException('Unknown resize mode');
        }
    }
}