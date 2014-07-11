<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=usertags.main
Tags=users.tpl:{USERS_DETAILS_DISPLAY_NAME}
[END_COT_EXT]
==================== */
/**
 * Region City plugin for Cotonti
 *
 * @package Cotonti Lib
 * @author Alex
 * @copyright Portal30 2014 http://portal30.ru
 */
defined('COT_CODE') or die('Wrong URL');

$temp_array['DISPLAY_NAME'] = '';
if($user_data['user_id'] > 0){
    $temp_array['DISPLAY_NAME'] = cot_user_display_name($user_data);
}
