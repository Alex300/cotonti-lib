<?php

declare(strict_types=1);

namespace image;

use image\exceptions\NotReadableException;

abstract class AbstractDecoder
{
    protected $data;

    /**
     * Initiates new image from path in filesystem
     * @param  string $path
     * @return array{
     *     data: resource|\Imagick|\GdImage,
     *     mime?: string,
     *     format: string,
     *     dirName: string,
     *     baseName: string,
     *     extension: string,
     *     fileName: string
     * }
     */
    abstract public function loadFromPath($path);

    /**
     * Initiates new image from binary data
     * @param  string $data
     * @return array{
     *     data: resource|\Imagick|\GdImage,
     *     mime: string,
     *     format: string,
     * }
     */
    abstract public function loadFromBinary($data);

    /**
     * Initiates new image from GD resource
     * @param  Resource $resource
     * @return array{data: resource|\GdImage}
     */
    abstract public function loadFromGd($resource);

    /**
     * Initiates new image from Imagick object
     *
     * @param \Imagick $object
     * @return array{
     *     data: \Imagick,
     *     fileName?: string,
     *     mime: string,
     *     format: string,
     * }
     */
    abstract public function loadFromImagick(\Imagick $object);

    /**
     * Initiates new image from mixed data
     *
     * @param mixed $data
     * @return array{
     *     data: resource|\Imagick|\GdImage,
     *     mime?: string,
     *     dirName?: string,
     *     baseName?: string,
     *     extension?: string,
     *     fileName?: string
     * }
     */
    public function load($data)
    {
        $this->data = $data;

        // @todo
        switch (true) {
            case $this->isGdResource():
                return $this->loadFromGd($this->data);

            case $this->isImagick():
                return $this->loadFromImagick($this->data);

//            case $this->isThisLibImage():
//                return $this->loadFromImage($this->data);

            case $this->isSplFileInfo():
                return $this->loadFromPath($this->data->getRealPath());

            case $this->isBinary():
                return $this->loadFromBinary($this->data);

            case $this->isUrl():
                return $this->loadFromUrl($this->data);

            case $this->isStream():
                return $this->loadFromStream($this->data);

            case $this->isDataUrl():
                return $this->loadFromBinary($this->decodeDataUrl($this->data));

            case $this->isFilePath():
                return $this->loadFromPath($this->data);

            // isBase64 has to be after isFilePath to prevent false positives
            case $this->isBase64():
                return $this->loadFromBinary(base64_decode($this->data));

            default:
                throw new NotReadableException('Image source not readable');
        }
    }

    /**
     * Determines if current source data is GD resource
     * @return bool
     */
    public function isGdResource()
    {
        if (is_resource($this->data)) {
            return (get_resource_type($this->data) == 'gd');
        }
        if (class_exists('GdImage') && ($this->data instanceof \GdImage)) {
            return true;
        }
        return false;
    }

    /**
     * Determines if current source data is Imagick object
     * @return bool
     */
    public function isImagick()
    {
        return $this->data instanceof \Imagick;
    }

    /**
     * Determines if current source data this lib Image object
     * @return bool
     */
    public function isThisLibImage()
    {
        return $this->data instanceof AbstractImage;
    }

    /**
     * Determines if current data is SplFileInfo object
     *
     * @return bool
     */
    public function isSplFileInfo()
    {
        return $this->data instanceof \SplFileInfo;
    }

    /**
     * Determines if current source data is file path
     * @return bool
     */
    public function isFilePath()
    {
        if (is_string($this->data)) {
            try {
                return is_file($this->data);
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Determines if current source data is url
     * @return bool
     */
    public function isUrl()
    {
        return (bool) filter_var($this->data, FILTER_VALIDATE_URL);
    }

    /**
     * Determines if current source data is a stream resource
     * @return bool
     */
    public function isStream()
    {
//        if ($this->data instanceof \StreamInterface) {
//            return true;
//        }
        if (!is_resource($this->data)) {
            return false;
        }
        if (get_resource_type($this->data) !== 'stream') {
            return false;
        }

        return true;
    }

    /**
     * Determines if current source data is binary data
     * @return bool
     */
    public function isBinary()
    {
        if (is_string($this->data) && function_exists('finfo_open')) {
            $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $this->data);
            return (substr($mime, 0, 4) != 'text' && $mime != 'application/x-empty');
        }
        return false;
    }

    /**
     * Determines if current source data is data-url
     * @return boolean
     */
    public function isDataUrl()
    {
        $data = $this->decodeDataUrl($this->data);
        return !is_null($data);
    }

    /**
     * Determines if current source data is base64 encoded
     * @return boolean
     */
    public function isBase64()
    {
        if (!is_string($this->data)) {
            return false;
        }
        return base64_encode(base64_decode($this->data)) === str_replace(["\n", "\r"], '', $this->data);
    }

    /**
     * Parses and decodes binary image data from data-url
     *
     * @param  string $data_url
     * @return string
     */
    private function decodeDataUrl($data_url)
    {
        if (!is_string($data_url)) {
            return null;
        }

        $pattern = "/^data:(?:image\/[a-zA-Z\-\.]+)(?:charset=\".+\")?;base64,(?P<data>.+)$/";
        preg_match($pattern, str_replace(["\n", "\r"], '', $data_url), $matches);

        if (is_array($matches) && array_key_exists('data', $matches)) {
            return base64_decode($matches['data']);
        }

        return null;
    }

//    /**
//     * Initiates new Image from this lib Image
//     * @param AbstractImage $object
//     * @return AbstractImage
//     */
//    public function loadFromImage($object)
//    {
//        return $object;
//    }

    /**
     * Init from given URL
     * @param  string $url
     * @return array{
     *     data: resource|\Imagick|\GdImage,
     *     mime: string,
     *     format: string
     * }
     */
    public function loadFromUrl($url)
    {
        $options = [
            'http' => [
                'method' => 'GET',
                'protocol_version' => 1.1, // force use HTTP 1.1 for service mesh environment with envoy
                'header' => "Accept-language: en\r\n".
                    "User-Agent: Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.71 Safari/537.36\r\n"
            ]
        ];

        $context  = stream_context_create($options);

        if ($data = @file_get_contents($url, false, $context)) {
            return $this->loadFromBinary($data);
        }

        throw new NotReadableException(
            "Unable to init from given url ({$url})."
        );
    }

    /**
     * Init from given stream.
     * @param resource $stream
     * @return array{
     *     data: resource|\Imagick|\GdImage,
     *     mime: string,
     *     format: string
     * }
     */
    public function loadFromStream($resource)
    {
        if (ftell($resource) !== 0 && stream_get_meta_data($resource)['seekable']) {
            rewind($resource);
        }

        $data = stream_get_contents($resource);
        if ($data) {
            return $this->loadFromBinary($data);
        }

        throw new NotReadableException(
            "Unable to init from given stream"
        );
    }

    /**
     * Sets file properties from given path
     * @param string $path
     * @return array{
     *     mime?: string,
     *     dirName: string,
     *     baseName: string,
     *     extension: string,
     *     fileName: string,
     *     orientation?: int
     * }
     */
    protected function getFileInfoFromPath($path)
    {
        $result = [
            'mime' => null,
            'orientation' => null,
        ];

        $info = pathinfo($path);
        $result['dirName'] = array_key_exists('dirname', $info) ? $info['dirname'] : null;
        $result['baseName'] = array_key_exists('basename', $info) ? $info['basename'] : null;
        $result['extension'] = array_key_exists('extension', $info) ? $info['extension'] : null;
        $result['fileName'] = array_key_exists('filename', $info) ? $info['filename'] : null;

        $exif = null;
        if (file_exists($path) && is_file($path)) {
            $result['mime'] = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
            $exif = @exif_read_data($path);
            if (!empty($exif) && !empty($exif['Orientation'])) {
                $result['orientation'] = $exif['Orientation'];
            }
        }

        return $result;
    }
}