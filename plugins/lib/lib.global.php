<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=global
Order=4
[END_COT_EXT]
==================== */
/**
 * Cotonti Lib plugin for Cotonti
 *
 * @package Cotonti Lib
 * @author  Kalnov Alexey    <kalnovalexey@yandex.ru>
 * @copyright © Portal30 Studio http://portal30.ru
 */
defined('COT_CODE') or die('Wrong URL.');

// Autoloader
require_once 'lib/Loader.php';
Loader::register();

/**
 * Standard var_dump with <pre>
 *
 * @param mixed $var[,$var1],[.. varN]
 */
function var_dump_() {
    echo '<div style="z-index:1000;opacity:0.8"><pre style="color:black;background-color:white;">';
    $params = func_get_args();
    call_user_func_array('var_dump', $params);
    echo '</pre></div>';
    ob_flush();
}

/**
 * Standard var_dump with <pre> and exit
 *
 * @param mixed $var[,$var1],[.. varN]
 */
function var_dump__() {
    $params = func_get_args();
    call_user_func_array('var_dump_', $params);
    exit;
}