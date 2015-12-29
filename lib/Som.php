<?php

/**
 * ORM System
 * SOM - Simple Object Manipulation
 *
 * @author Gert Hengeveld (ORM from cot-factory)
 * @author Mankov
 * @author Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 * @version 1.3.1
 *
 *
 *  @todo Models   use cot_build_extrafields_data and cot_parse
 *                 В общем вывод в форму и на страницу нужно как то разделить
 *                 Вероятно при выводе на страницу добавить эти функции в геттер
 *                 А в форму ставить rawValue
 */
class Som
{
    const TO_ONE = 'toone';
    const TO_ONE_NULL = 'toonenull';
    const TO_MANY = 'tomany';
    const TO_MANY_NULL = 'tomanynull';

    /**
     * Adapter Factory
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
    public static function getAdapter($db = 'db', $dbinfo = array()) {

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