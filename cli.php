<?php
/**
 * CLI Index loader
 *
 * Used to run Cotonti from command line
 *
 * @package Cotonti
 * @author Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 */
define('CLI', 1);
ini_set('display_errors', 1);

// Let the include files know that we are Cotonti
define('COT_CODE', true);

// Load vital core configuration from file
require_once './datas/config.php';

$cfg['display_errors'] = true;

// Load the Core API, the template engine
require_once $cfg['system_dir'] . '/functions.php';
require_once $cfg['system_dir'] . '/cotemplate.php';

// Bootstrap
require_once $cfg['system_dir'] . '/common.php';


define('ARGV1', $argv[1]);

if(empty($argv[1])) {
    echo "NO CALL. EXAMPLE > cli.php --a module.controller.action\n";
    exit;
}

// Detect selected extension
if($num = array_search('--a', $argv)) {
    $param = $argv[$num+1];
    if(empty($param)){
        echo "Parameter required. EXAMPLE > cli.php --a module.controller.action\n";
    } else {
        list($e, $m, $a) = explode('.',$param);

        $found = false;
        if (preg_match('`^\w+$`', $e)) {
            $module_found = false;
            $plugin_found = false;
            if (file_exists($cfg['modules_dir'] . '/' . $e) && isset($cot_modules[$e])) {
                $module_found = true;
                $found = true;
            }
            if (file_exists($cfg['plugins_dir'] . '/' . $e)) {
                $plugin_found = true;
                $found = true;
            }
            if ($module_found && $plugin_found) {
                // Need to query the db to check which one is installed
                $res = $db->query("SELECT ct_plug FROM $db_core WHERE ct_code = ? LIMIT 1", $e);
                if ($res->rowCount() == 1) {
                    if ((int)$res->fetchColumn()) {
                        $module_found = false;
                    } else {
                        $plugin_found = false;
                    }
                } else {
                    $found = false;
                }
            }
            if ($module_found) {
                $env['type'] = 'module';
                define('COT_MODULE', true);
            } elseif ($plugin_found) {
                $env['type'] = 'plug';
                $env['location'] = 'plugins';
                define('COT_PLUG', true);
            }
        }
        if ($found) {
            $env['ext'] = $e;
        } else {
            // Error page
            echo "Extension '{$e}' not found\n";
            exit;
        }

        $content = '';
        echo "\nStarting '{$e}' at ".date('Y-m-d H:i:s')."\n";

        // Load the requested extension
        if ($env['type'] == 'plug') {
            require_once $cfg['system_dir'] . '/plugin.php';

        } else {
            require_once $cfg['modules_dir'] . '/' . $env['ext'] . '/' . $env['ext'] . '.php';
        }

        if(!empty($content)){
            echo "---------------------------------------------------------------------------\n";
            echo $content."\n";
            echo "---------------------------------------------------------------------------\n";
        }
        unset($Controller);
        echo "Stopped at ".date('Y-m-d H:i:s')."\n";
        //ob_end_flush();
    }
}

exit;
