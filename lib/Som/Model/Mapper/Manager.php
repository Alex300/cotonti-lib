<?php
/**
 * DB connection Mapper Factory
 */

class Som_Model_Mapper_Manager {

    /**
     * Mapper Factory
     *
     * @param string $db
     * @param array $dbinfo
     *
     * Db connection config example:
     *
     * cot::$cfg['db_avtochuvasiya'] = array(
     *      'adapter' => 'mysql',   // 'pgsql' or 'mongo'
     *      'host' => '127.0.0.1',
     *      'port' => null,
     *      'username' => 'root',
     *      'password' => '123456',
     *      'dbname' => 'avtochuvashija',
     * );
     *
     *
     * @return Som_Model_Mapper_Abstract
     * @throws Exception
     */
    public static function getMapper($db = 'db', $dbinfo = array()) {

        // Default cotonti connection
        if($db == 'db') return new Som_Model_Mapper_Mysql($dbinfo, 'db');

        if(empty(cot::$cfg[$db]) || empty(cot::$cfg[$db]['adapter'])) {
            throw new Exception('Connection config not found in $cfg['.$db.']');
        }

        $dbtype = cot::$cfg[$db]['adapter'];

        switch ($dbtype) {
            case 'mysql':
                return new Som_Model_Mapper_Mysql($dbinfo, $db);
                break;

            case 'pgsql':
                return new Som_Model_Mapper_Pgsql($dbinfo, $db);
                break;

            case 'mongo':
                return new Som_Model_Mapper_Mongo($dbinfo, $db);

            default:
                throw new Exception("DB Adapter not found «{$dbtype}»");
        }
    }

}
