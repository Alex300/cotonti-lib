<?php
/**
 * Фабрика мапперов
 */

class Som_Model_Mapper_Manager {

    /**
     * @access public
     *
     * @param array $dbinfo
     * @param string $db
     *
     * @return Som_Model_Mapper_Abstract
     */
    public static function getMapper($dbinfo = array(), $db = 'db') {

        //$dbtype = Kernel::$config[$db]['adapter'];
        $dbtype = 'mysql';

        switch ($dbtype) {
            case 'mysql':
                return new Som_Model_Mapper_Mysql($dbinfo, $db);
                break;
            case 'pgsql':
                return new Som_Model_Mapper_Pgsql($dbinfo, $db);
                break;
        }
    }


}
