<?php

namespace lib;

defined('COT_CODE') or die('Wrong URL.');

/**
 * Db Query builder and ORM System (SOM)
 *
 * SOM - Simple Object Manipulation
 *
 * @author Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 * @version 3.0
 *
 *  @todo Models   use cot_build_extrafields_data and cot_parse
 *                 В общем вывод в форму и на страницу нужно как то разделить
 *                 Вероятно при выводе на страницу добавить эти функции в геттер
 *                 А в форму ставить rawValue
 *
 * Almost Fixed: https://github.com/Alex300/cotonti-lib/issues/3 (added \Som\Adapter::junctionTable())
 */
class Db
{
    /**
     * A one-to-one relationship.
     *
     * Relationship with another model by field in a related object (foreign key).
     * Default 'foreignKey': singular form of table name
     * Default 'localKey': primary key
     */
    const HAS_ONE = 'has_one';

    /**
     * A one-to-many relationship.
     *
     * Relationship with another model by field in a related object (foreign key).
     * Default 'foreignKey': By default, it is a singular form of table name of the owning model with suffix '_id'.
     * Default 'localKey': primary key
     */
    const HAS_MANY = 'has_many';

    /**
     * Inverse for HAS_ONE и HAS_MANY
     *
     * Default 'foreignKey': primary key of related model
     * Default 'localKey': singular form of related table name
     */
    const BELONGS_TO = 'belongs_to';

    /**
     * Many-to-many relations.
     * Relations via a junction table
     * Junction table name is the alphabetical order of the related table names
     */
    const MANY_TO_MANY = 'many_to_many';

    /*
     * Example:
     *      User - user model. Order - order model.
     *      User HAS_MANY Order (user has many orders),  but Order BELONGS_TO User (order only has one user)
     *      In this case the by default `orders` table should have `user_id` field.
     */

    const MYSQL = 'mysql';
    const POSTGRESQL = 'pgsql';
    const MONGO = 'mongo';

    /**
     * Adapters registry. Wa are using one adapter per DB connection.
     * @var \lib\Db\Adapter[]
     */
    protected static $adapters = [];

    /**
     * Adapter Factory
     *
     * @param string $dbc Connection Name
     *
     * Db connection config example:
     *
     * cot::$cfg['db_connection_name'] = array(
     *      'adapter' => Db::MYSQL,     // Database type. Db::MYSQL, Db::ADAPTER_POSTGRESQL or Db::ADAPTER_MONGO
     *      'host' => '127.0.0.1',
     *      'port' => null,
     *      'prefix' => '',             // Table prefix
     *      'username' => 'notroot',
     *      'password' => '123456',
     *      'dbname' => 'data_base_name',
     *      'charset' => 'utf8mb4'
     * );
     *
     *
     * @return \lib\Db\Adapter
     * @throws \Exception
     */
    public static function adapter($dbc = 'db') {
        if (empty(self::$adapters[$dbc])) {

            // Default cotonti connection
            if($dbc == 'db') {
                self::$adapters[$dbc] = new \lib\Db\Mysql\Adapter('db');

            } else {
                $cfg = Db::connectionSettings($dbc);

                $dbType = $cfg['adapter'];

                $adapterClass = '\lib\Db\\'.$dbType.'\Adapter';
                if(!class_exists($adapterClass)) throw new \Exception("DB Adapter not found «{$dbType}»");

                self::$adapters[$dbc] =  new $adapterClass($dbc);
            }
        }

        return self::$adapters[$dbc];
    }

    /**
     * Get connection settings by it's name
     */
    public static function connectionSettings($dbc = 'db')
    {
        // Default Cotonti connection
        // Правда к этому времени host, port, user и password уже затерты
        if($dbc == 'db') {
            $ret = [
                'adapter' => Db::MYSQL,
                'host' => \cot::$cfg['mysqlhost'],
                'port' => \cot::$cfg['mysqlport'],
                'prefix' => \cot::$db_x,
                'username' => \cot::$cfg['mysqluser'],
                'password' => \cot::$cfg['mysqlpassword'],
                'dbname' => \cot::$cfg['mysqldb'],
                'charset' => \cot::$cfg['mysqlcharset']
            ];

        } else {
            if (empty(\cot::$cfg[$dbc]) || empty(\cot::$cfg[$dbc]['adapter'])) {
                throw new \Exception('Connection config not found in $cfg[' . $dbc . ']');
            }

            $ret = \cot::$cfg[$dbc];
        }

        $ret['name'] = $dbc;

        return $ret;
    }
}