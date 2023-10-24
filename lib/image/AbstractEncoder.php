<?php

declare(strict_types=1);

namespace image;

use image\exceptions\InvalidArgumentException;

abstract class AbstractEncoder
{
    /**
     * Buffer of encode result data
     *
     * @var string
     */
    public $result;

    /**
     * Image object to encode
     * @var ?AbstractImage
     */
    public $image;

    /**
     * Output format of encoder instance
     *
     * @var string
     */
    public $format;

    /**
     * Output quality of encoder instance
     *
     * @var int
     */
    public $quality;

    /**
     * @param ?string $fileName File name to save encoded image. If is null - encoded image will be returned as string
     * @return bool|string If $fileName is passed it will return bool (success file saved or not),
     *    otherwise it returns encoded image as string
     */
    protected abstract function process($fileName = null);

    /**
     * Process a given image
     *
     * @param AbstractImage $image
     * @param ?string $fileName File name to save encoded image. If is null - encoded image will be returned as string
     * @param string $format
     * @param int $quality
     * @return bool|string If $fileName is passed it will return bool (success file saved or not),
     *    otherwise it returns encoded image as string
     */
    public function encode(AbstractImage $image, $fileName = null, $format = null, $quality = null)
    {
        $this->setImage($image);
        $this->setFormat($format);
        $this->setQuality($quality);

        switch (strtolower($this->format)) {
            case 'data-url':
                $this->result = $this->processDataUrl();
                break;

            default:
                $this->result = $this->process($fileName);
        }

        $this->setImage(null);

        return $this->result;
    }

    /**
     * Processes and returns encoded image as data-url string
     * @return string
     */
    protected function processDataUrl()
    {
        $mime = $this->image->getMime();
        $format = $this->image->getFormat();
        if (empty($mime) || empty($format)) {
            $mime = 'image/png';
            $format = Format::PNG;
        }
        return sprintf('data:%s;base64,%s',
            $mime,
            base64_encode($this->encode($this->image, $format, $this->quality))
        );
    }

    /**
     * Sets image to process
     * @param ?AbstractImage $image
     */
    protected function setImage($image)
    {
        $this->image = $image;
    }

    /**
     * Determines output format
     * @param string $format
     */
    protected function setFormat($format = null)
    {
        if (empty($format) && $this->image instanceof AbstractImage) {
            // @todo possibility to use mime type. Convert mime to extension with \image\Format
            $format = $this->image->getFormat();
        }

        if (empty($format) || $format === 'jpg') {
            $format = Format::JPEG;
        }

        $this->format = $format;

        return $this;
    }

    /**
     * Determines output quality
     * For PNG there are to digits:
     *   first digit: compression level (default: 7)
     *   second digit: compression filter (default: 5)
     *   So default $quality is 75
     * @param ?int $quality
     */
    protected function setQuality(?int $quality = null)
    {
        if (in_array($this->format, [Format::JPEG, Format::WEBP, Format::TIFF, Format::AVIF, Format::HEIC])) {
            $quality = $quality === null ? 90 : (int) $quality;
            $quality = $quality === 0 ? 1 : $quality;

            if ($quality < 0 || $quality > 100) {
                throw new InvalidArgumentException('Quality must range from 0 to 100.');
            }
        } elseif ($this->format === Format::PNG) {
            $quality = $quality === null ? 75 : (int) $quality;
            $quality = $quality === 0 ? 15 : $quality;

            // If not passed compression filter
            if ($quality < 10) {
                $quality = $quality * 10 + 5;
            }

            if ($quality < 0 || $quality > 100) {
                throw new InvalidArgumentException('Quality must range from 0 to 100.');
            }
        }

        $this->quality = $quality;

        return $this;
    }
}