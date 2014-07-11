<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=global
[END_COT_EXT]
==================== */
/**
 * Region City plugin for Cotonti
 *
 * @package Cotonti Lib
 * @author Alex
 * @copyright Portal30 2014 http://portal30.ru
 */
defined('COT_CODE') or die('Wrong URL.');

// Autoloader
require_once 'lib/Loader.php';
Loader::register();

if(!function_exists('cot_user_display_name')){
    /**
     * user display name
     * @param array|int $user User Data or User ID
     * @return array
     */
    function cot_user_display_name($user){

        if( is_int($user) || ctype_digit($user) ) $user = cot_user_data($user);
        if(empty($user)) return '';

        if(!empty($user['user_firstname']) || !empty($user['user_lastname']) || !empty($user['user_middlename'])){
            return trim($user['user_lastname'].' '.$user['user_firstname'].' '.$user['user_middlename']);
        }

        if(!empty($user['user_first_name']) || !empty($user['user_last_name']) || !empty($user['user_middle_name'])){
            return trim($user['user_last_name'].' '.$user['user_first_name'].' '.$user['user_middle_name']);
        }

        return $user['user_name'];
    }
}

if(!function_exists('cot_user_data')){
    /**
     * Fetches user entry from DB
     *
     * @param int $uid User ID
     * @param bool $cacheitem
     * @return array
     */
    function cot_user_data($uid = 0, $cacheitem = true){
        global $db_users;

        $user = false;

        if(!$uid && cot::$usr['id'] > 0){
            $uid = cot::$usr['id'];
            $user = cot::$usr['profile'];
        }
        if(!$uid) return null;

        static $u_cache = array();

        if($cacheitem && isset($u_cache[$uid])) {
            return $u_cache[$uid];
        }

        if(!$user){
            if(is_array($uid)){
                $user = $uid;
                $uid = $user['user_id'];
            }else{
                if($uid > 0 && $uid == cot::$usr['id']){
                    $user = cot::$usr['profile'];
                }else{
                    $uid = (int)$uid;
                    if(!$uid) return null;
                    $sql = cot::$db->query("SELECT * FROM $db_users WHERE user_id = ? LIMIT 1", $uid);
                    $user = $sql->fetch();
                }
            }
        }

        $cacheitem && $u_cache[$uid] = $user;

        return $user;
    }
}