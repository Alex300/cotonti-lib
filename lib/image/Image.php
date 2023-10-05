<?php

declare(strict_types=1);

namespace image;

use image\exceptions\ImageException;
use image\exceptions\NotSupportedException;

/**
 * Image service. Factory and config
 */
class Image
{
    const DRIVER_GD = 'gd';
    const DRIVER_IMAGICK = 'imagick';

    /**
     * Image filter: none/undefined.
     *
     * @var string
     */
    const FILTER_UNDEFINED = 'undefined';

    /**
     * Resampling filter: point (interpolated).
     */
    const FILTER_POINT = 'point';

    /**
     * Resampling filter: box.
     */
    const FILTER_BOX = 'box';

    /**
     * Resampling filter: triangle.
     */
    const FILTER_TRIANGLE = 'triangle';

    /**
     * Resampling filter: hermite.
     */
    const FILTER_HERMITE = 'hermite';

    /**
     * Resampling filter: hanning.
     */
    const FILTER_HANNING = 'hanning';

    /**
     * Resampling filter: hamming.
     */
    const FILTER_HAMMING = 'hamming';

    /**
     * Resampling filter: blackman.
     */
    const FILTER_BLACKMAN = 'blackman';

    /**
     * Resampling filter: gaussian.
     */
    const FILTER_GAUSSIAN = 'gaussian';

    /**
     * Resampling filter: quadratic.
     */
    const FILTER_QUADRATIC = 'quadratic';

    /**
     * Resampling filter: cubic.
     */
    const FILTER_CUBIC = 'cubic';

    /**
     * Resampling filter: catrom.
     */
    const FILTER_CATROM = 'catrom';

    /**
     * Resampling filter: mitchell.
     */
    const FILTER_MITCHELL = 'mitchell';

    /**
     * Resampling filter: lanczos.
     */
    const FILTER_LANCZOS = 'lanczos';

    /**
     * Resampling filter: bessel.
     */
    const FILTER_BESSEL = 'bessel';

    /**
     * Resampling filter: sinc.
     */
    const FILTER_SINC = 'sinc';

    /**
     * Resampling filter: sincfast.
     */
    const FILTER_SINCFAST = 'sincfast';

    /**
     * The original image is scaled so it is fully contained within the thumbnail dimensions (the image width/height ratio doesn't change).
     */
    const THUMBNAIL_INSET = 'inset';

    /**
     * The thumbnail is scaled so that its smallest side equals the length of the corresponding side in the original image
     * other side (the width or the height) is cropped.
     */
    const THUMBNAIL_OUTBOUND = 'outbound';

    /**
     * The thumbnail is scaled so that its height equals the desired height (the image width/height ratio doesn't change).
     */
    const THUMBNAIL_HEIGHT = 'height';

    /**
     * The thumbnail is scaled so that its width equals the desired width (the image width/height ratio doesn't change).
     */
    const THUMBNAIL_WIDTH = 'width';

    const ORIENTATION_UNDEFINED = 0;

    /**
     * Horizontal (normal)
     */
    const ORIENTATION_TOPLEFT = 1;

    /**
     * Mirror horizontal
     */
    const ORIENTATION_TOPRIGHT = 2;

    /**
     * Rotate 180
     */
    const ORIENTATION_BOTTOMRIGHT = 3;

    /**
     * Mirror vertical
     */
    const ORIENTATION_BOTTOMLEFT = 4;

    /**
     * Mirror horizontal and rotate 270 CW
     */
    const ORIENTATION_LEFTTOP = 5;

    /**
     * Rotate 90 CW
     */
    const ORIENTATION_RIGHTTOP = 6;

    /**
     * Mirror horizontal and rotate 90 CW
     */
    const ORIENTATION_RIGHTBOTTOM = 7;

    /**
     * Rotate 270 CW
     */
    const ORIENTATION_LEFTBOTTOM = 8;

    protected static $config = [
        'driver' => null,

        /**
         * Возможность использовать множество сохранений подряд:
         * $image->save($newName . '.png');
         * $image->save($newName . '.bmp');
         * $image->save($newName . '.gif');
         * или
         * $image->save($newName . '.png')->save($newName . '.bmp')->$image->save($newName . '.gif');
         *
         * Может вызвать дополнительное потребление памяти т.к. для каждого сохранения будет создавать клон изображения для проведения манипуляций
         * с ним при конвертировании в нужный формат
         */
        'multipleSave' => false,
    ];

    /**
     * @param string $path
     * @param array|null $options
     * @return AbstractImage
     */
    public static function load($path, $options = null)
    {
        return self::initDriver($options)->load($path);
    }

    /**
     * @param array $options
     * @return void
     */
    public static function setConfig($options)
    {
        if (!empty($options)) {
            if (is_string($options)) {
                $options = ['driver' => $options];
            }
            foreach ($options as $option => $value) {
                if (array_key_exists($option, self::$config)) {
                    self::$config[$option] = $value;
                }
            }
        }

        if (empty(self::$config['driver']) && !empty(\Cot::$cfg['imageLibrary'])) {
            self::$config['driver'] = \Cot::$cfg['imageLibrary'];
        }
    }

    public static function config($option = null)
    {
        if ($option !== null) {
            return isset(self::$config[$option]) ? self::$config[$option] : null;
        }
        return self::$config;
    }

    /**
     * @return string[]
     */
    public static function supportedFormats()
    {
        try {
            return self::initDriver()->getSupportedFormats();
        } catch (ImageException $e) {
            return [];
        }
    }

    public static function currentDriver()
    {
        try {
            $driver = static::initDriver();
        } catch (ImageException $e) {
            return null;
        }

        if ($driver instanceof imagick\Image) {
            return self::DRIVER_IMAGICK;
        }

        if ($driver instanceof gd\Image) {
            return self::DRIVER_GD;
        }

        return null;
    }

    /**
     * Image processor factory
     * @param array|null $options
     * @return AbstractImage
     */
    protected static function initDriver($options = null)
    {
        self::setConfig($options);

        if (self::$config['driver'] === null || self::$config['driver'] === self::DRIVER_IMAGICK) {
            $driver = new imagick\Image();
            if ($driver->isAvailable()) {
                return $driver;
            }
        }

        if (self::$config['driver'] === null || self::$config['driver'] === self::DRIVER_GD) {
            $driver = new gd\Image();
            if ($driver->isAvailable()) {
                return $driver;
            }
        }

        if (self::$config['driver'] !== null) {
            if (in_array(self::$config['driver'], [self::DRIVER_GD, self::DRIVER_IMAGICK])) {
                throw new NotSupportedException(
                    'Driver (' . self::$config['driver'] . ') could not be instantiated.'
                );
            } else {
                throw new NotSupportedException(
                    'Unknown driver type: (' . self::$config['driver'] . ').'
                );
            }
        }

        throw new NotSupportedException(
            'No any image processing driver available.'
        );
    }
}