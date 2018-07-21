<?php

/**
 * ORM System
 * SOM - Simple Object Manipulation
 *
 * @author Gert Hengeveld (ORM from cot-factory)
 * @author Mankov
 * @author Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 * @version 3.0
 *
 *  @todo Models   use cot_build_extrafields_data and cot_parse
 *                 В общем вывод в форму и на страницу нужно как то разделить
 *                 Вероятно при выводе на страницу добавить эти функции в геттер
 *                 А в форму ставить rawValue
 *
 * @todo у разных соединений с БД могут быть разные префиксы таблиц
 *       по идее у нас адаптер - это Connection, Можно в нем хранить
 * @see C:/OpenServer/domains/laravel.loc/vendor/laravel/framework/src/Illuminate/Database/Connection.php
 *
 *
 * Fixed: https://github.com/Alex300/cotonti-lib/issues/3 (added \Som\Adapter::junctionTable())
 * Fixed:
 */
class Som
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
     * Default 'foreignKey': singular form of table name
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

    /**
     * @deprecated use BELONGS_TO instead with parameter nullable = false
     */
    const TO_ONE = self::BELONGS_TO;

    /**
     * @deprecated use BELONGS_TO instead
     */
    const TO_ONE_NULL = self::BELONGS_TO;

    /**
     * @deprecated use MANY_TO_MANY instead
     */
    const TO_MANY = self::MANY_TO_MANY;

    /**
     * @deprecated use MANY_TO_MANY instead
     */
    const TO_MANY_NULL = self::MANY_TO_MANY;


    const ADAPTER_MYSQL = 'mysql';
    const ADAPTER_POSTGRESQL = 'pgsql';
    const ADAPTER_MONGO = 'mongo';

    /**
     * Adapter Factory
     *
     * @param string $db        Connection Name
     * @param array  $dbInfo
     *
     * Db connection config example:
     *
     * cot::$cfg['db_connection_name'] = array(
     *      'adapter' => Som::ADAPTER_MYSQL,   // Som::ADAPTER_POSTGRESQL or Som::ADAPTER_MONGO
     *      'host' => '127.0.0.1',
     *      'port' => null,
     *      'username' => 'notroot',
     *      'password' => '123456',
     *      'dbname' => 'data_base_name',
     * );
     *
     *
     * @return \Som\Adapter
     * @throws Exception
     */
    public static function getAdapter($db = 'db', $dbInfo = array()) {

        // Default cotonti connection
        if($db == 'db') return new \Som\Adapter\Mysql('db', $dbInfo);

        if(empty(cot::$cfg[$db]) || empty(cot::$cfg[$db]['adapter'])) {
            throw new Exception('Connection config not found in $cfg['.$db.']');
        }

        $dbType = cot::$cfg[$db]['adapter'];

        switch ($dbType) {
            case Som::ADAPTER_MYSQL:
                return new \Som\Adapter\Mysql($db, $dbInfo);
                break;

            case Som::ADAPTER_POSTGRESQL:
                return new \Som\Adapter\Pgsql($db, $dbInfo);
                break;

            case Som::ADAPTER_MONGO:
                return new \Som\Adapter\Mongo($db, $dbInfo);

            default:
                throw new \Exception("DB Adapter not found «{$dbType}»");
        }
    }

}