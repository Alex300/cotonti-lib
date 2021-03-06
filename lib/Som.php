<?php

/**
 * ORM System
 * SOM - Simple Object Manipulation
 *
 * @author Gert Hengeveld (ORM from cot-factory)
 * @author Mankov
 * @author Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 * @version 2.0
 *
 * @todo типы связей:
 *  HAS_ONE  - A one-to-one relationship
 *      вернёт связный объект Active Record. Производит выборку объекта по указанному полю в связанном объекте (foreign key).
 *      Если поле не указано, может быть получени автоматически
 *
 *  HAS_MANY - A "one-to-many" relationship
 *       вернёт массив связных объектов Active Record. Производит выборку объектов по указанному полю в связанном объекте (foreign key)
 *       Если поле не указано, может быть получени автоматически
 *
 *  BELONGS_TO - inverse for HAS_ONE и HAS_MANY
 *       вернет связанный объект Active Record. Производит выборку объекта по указанному своему полю (local_key).
 *       Сейчас это TO_ONE
 *
 *       Example:
 *          User - модель пользователя. Order - модель заказа. Order имеет поле user_id.
 *          User HAS_MANY Order (Пользователь имеет много заказов),   а Order BELONGS_TO User (Заказ принадлежит пользователю)
 *
 *  BELONGS_TO_MANY (или MANY_TO_MANY)- Many-to-many relations
 *       Использует промежуточную таблицу для связи. Можно указывать промежуточную таблицу или она будет вычисляться автоматически
 *       Сейчас это TO_MANY
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
     * @return Som_Model_Mapper_Abstract
     * @throws Exception
     */
    public static function getAdapter($db = 'db', $dbInfo = array()) {

        // Default cotonti connection
        if($db == 'db') return new Som_Model_Mapper_Mysql('db', $dbInfo);

        if(empty(cot::$cfg[$db]) || empty(cot::$cfg[$db]['adapter'])) {
            throw new Exception('Connection config not found in $cfg['.$db.']');
        }

        $dbType = cot::$cfg[$db]['adapter'];

        switch ($dbType) {
            case Som::ADAPTER_MYSQL:
                return new Som_Model_Mapper_Mysql($db, $dbInfo);
                break;

            case Som::ADAPTER_POSTGRESQL:
                return new Som_Model_Mapper_Pgsql($db, $dbInfo);
                break;

            case Som::ADAPTER_MONGO:
                return new Som_Model_Mapper_Mongo($db, $dbInfo);

            default:
                throw new Exception("DB Adapter not found «{$dbType}»");
        }
    }

}