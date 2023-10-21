<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=global
Order=4
[END_COT_EXT]
==================== */
/**
 * Cotonti Lib plugin for Cotonti Siena
 *
 * @package Cotonti Lib
 * @author  Kalnov Alexey    <kalnovalexey@yandex.ru>
 * @copyright © Portal30 Studio http://portal30.ru
 */
defined('COT_CODE') or die('Wrong URL.');

// Autoloader
require_once 'lib/Loader.php';
Loader::register();

include cot_langfile('cotontilib', 'plug');

if (!function_exists('cot_getHttpStatusByCode')) {
    /**
     * Terminates further script execution with a given
     * HTTP response status and output.
     * If the message is omitted, then it is taken from the
     * HTTP status line.
     * @param int $code HTTP/1.1 status code
     * @param string $response Output string
     */
    function cot_ajaxResult($response = null, $code = 200)
    {
        $status = cot_getHttpStatusByCode($code);
        cot_sendheaders('application/json', $status);
        if ($response === null) {
            $response = substr($status, strpos($status, ' ') + 1);
        }

        echo json_encode($response);

        exit;
    }
}

if (!function_exists('cot_getHttpStatusByCode')) {
    /**
     * Returns HTTP satus line for a given
     * HTTP response code
     * @param int $code HTTP response code
     * @return string       HTTP status line
     */
    function cot_getHttpStatusByCode($code)
    {
        static $msg_status = [
            200 => '200 OK',
            201 => '201 Created',
            204 => '204 No Content',
            205 => '205 Reset Content',
            206 => '206 Partial Content',
            300 => '300 Multiple Choices',
            301 => '301 Moved Permanently',
            302 => '302 Found',
            303 => '303 See Other',
            304 => '304 Not Modified',
            307 => '307 Temporary Redirect',
            400 => '400 Bad Request',
            401 => '401 Authorization Required',
            403 => '403 Forbidden',
            404 => '404 Not Found',
            409 => '409 Conflict',
            411 => '411 Length Required',
            500 => '500 Internal Server Error',
            501 => '501 Not Implemented',
            503 => '503 Service Unavailable',
        ];

        if (isset($msg_status[$code])) {
            return $msg_status[$code];
        }

        return "$code Unknown";
    }
}

if (!function_exists('mb_lcfirst')) {
    /**
     * Make a string's first character lowercase
     * @link http://php.net/manual/en/function.lcfirst.php
     *
     * @param string $str The input string.
     * @return string the resulting string.
     */
    function mb_lcfirst($str)
    {
        $fc = mb_strtolower(mb_substr($str, 0, 1));
        return $fc . mb_substr($str, 1);
    }
}

if (!function_exists('mb_ucfirst')) {
    /**
     * Make a string's first character uppercase
     * @link http://php.net/manual/en/function.ucfirst.php
     *
     * @param string $str The input string.
     * @return string the resulting string.
     */
    function mb_ucfirst($str)
    {
        $fc = mb_strtoupper(mb_substr($str, 0, 1));
        return $fc . mb_substr($str, 1);
    }
}

if (!function_exists('cot_removeDirectoryRecursive')) {
    /**
     * Recursive remove directory
     * @param string $dir
     * @return bool
     */
    function cot_removeDirectoryRecursive($dir)
    {
        if (empty($dir) && $dir != '0') {
            return false;
        }

        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (filetype($dir . '/' . $object) == 'dir') {
                        cot_removeDirectoryRecursive($dir . '/' . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
        return true;
    }
}

if (!function_exists('cot_slug')) {
    function cot_slug($string)
    {

    }
}

/**
 * Returns transliterated version of a string.
 *
 * If intl extension isn't available uses fallback
 * You may customize characters map via $cot_translit_custom variable. See file lang/ru/translit.ru.lang.php
 *
 * @param string $string input string
 * @param string|\Transliterator $transliterator either a \Transliterator or a string
 *                                               from which a \Transliterator can be built.
 * @see http://php.net/manual/en/transliterator.transliterate.php
 *
 * @return string
 *
 * Todo check if russian settings are right
 */
if (!function_exists('cot_transliterate')) {
    function cot_transliterate($string, $transliterator = null)
    {
        global $cot_translit, $cot_translit_custom, $cot_transliterator;

        include cot_langfile('translit', 'core');

        if (is_array($cot_translit_custom)) return strtr($string, $cot_translit_custom);

        if (extension_loaded('intl')) {
            if ($transliterator === null) $transliterator = $cot_transliterator;
            if ($transliterator === null) $transliterator = 'Any-Latin; Latin-ASCII; [\u0080-\uffff] remove';

            return transliterator_transliterate($transliterator, $string);
        }

        if (is_array($cot_translit)) {
            return strtr($string, $cot_translit);
        }

        return $string;
    }
}

/**
 * Standard var_dump with <pre>
 *
 * @param mixed $var[,$var1],[.. varN]
 */
if (!function_exists('var_dump_')) {
    function var_dump_()
    {
        static $cnt = 0;
        $cnt++;
        echo '<div id="var-dump-' . $cnt . '" class="var-dump" style="z-index:1000;opacity:0.8"><pre style="color:black;background-color:white;">';
        $params = func_get_args();
        call_user_func_array('var_dump', $params);
        echo '</pre></div>';
        ob_flush();
    }
}

/**
 * Standard var_dump with <pre> and exit
 *
 * @param mixed $var[,$var1],[.. varN]
 */
if (!function_exists('var_dump__')) {
    function var_dump__()
    {
        cot_sendheaders();
        $params = func_get_args();
        call_user_func_array('var_dump_', $params);
        exit;
    }
}

// ==== View template functions ====
if (!function_exists('cot_formGroupClass')) {
    /**
     * Класс для элемента формы (подсветка ошибок)
     * @param $name
     * @return string
     */
    function cot_formGroupClass($name)
    {
        global $currentMessages;

        if (!cot::$cfg['msg_separate']) {
            return '';
        }

        $error = cot_implode_messages($name, 'error');
        if ($error) {
            return 'has-error';
        }

        if (!empty($currentMessages[$name]) && is_array($currentMessages[$name])) {
            foreach ($currentMessages[$name] as $msg) {
                if ($msg['class'] == 'error') {
                    return 'has-error';
                }
            }
        }

        return '';
    }
}
// ==== /View template functions ====