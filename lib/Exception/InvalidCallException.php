<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace lib\Exception;

defined('COT_CODE') or die('Wrong URL.');

/**
 * InvalidCallException represents an exception caused by calling a method in a wrong way.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 */
class InvalidCallException extends \BadMethodCallException
{
    /**
     * @return string the user-friendly name of this exception
     */
    public function getName()
    {
        return 'Invalid Call';
    }
}
