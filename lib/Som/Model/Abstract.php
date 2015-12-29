<?php

/**
 * ORM System
 * SOM - Simple Object Manipulation
 *
 * @package Cotonti Lib
 * @subpackage SOM
 *
 * @author Gert Hengeveld (ORM from cot-factory)
 * @author Mankov
 * @author Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 *
 */
abstract class Som_Model_Abstract
{
    /**
     * @var Som_Model_Mapper_Abstract
     */
    protected static $_db = null;
    protected static $_tbname = null;
    protected static $_primary_key = null;

    /**
     * @var array
     */
    public $_errors = array();

    /**
     * @var string
     */
    protected static $_dbtype;

    protected static $_stCache = array();

    /**
     * Данные в соотвествие с полями в БД
     * @var array
     */
    protected $_data = array();

    /**
     * Данные связей
     * @var array
     */
    protected $_linkData = array();

    /**
     * Поле, для хранения временных данных. Их можно записать и прочитать, Они не сохраняются в БД
     * Например $user->auth_write = true
     * @var array
     */
    protected $_extraData = array();

    /**
     * Поле, для хранения старых данных. Тех, что были до вызова сеттера для данного поля. Позволяет контролировать
     * изменилось ли поле.
     * @var array
     */
    protected $_oldData = array();

    /**
     * @var array Список полей, описанный в моделе и/или получаемый из полей БД. С типами данных.
     */
    protected static $fields = array();

    /**
     * Model extrafields. Поля присутсвующие в таблице, но не описанные в fieldList().
     * Они могут быть созданы другими модулями
     * @var array
     */
    protected static $_extraFields = array();

    protected $_e;

    protected $validators = array();


    /**
     * Static constructor
     * @param string $db Data base connection config name
     * Модель не проверяет наличие таблицы и всех полей, а наивно доверяет данным из метода fieldList()
     * @throws Exception
     */
    public static function __init($db = 'db'){
        global $cot_extrafields;

        if($db == 'db') {
            static::$_dbtype = 'mysql';
        } else {
            if(empty(cot::$cfg[$db]) || empty(cot::$cfg[$db]['adapter'])) {
                throw new Exception('Connection config not found in $cfg['.$db.']');
            }

            static::$_dbtype = cot::$cfg[$db]['adapter'];
        }
        static::$_db = $dbAdapter = static::getAdapter($db);

        $className = get_called_class();
        self::$_extraFields[$className] = array();

        // load Extrafields
        if(!empty($cot_extrafields[static::$_tbname])) {
            // Не очень хорошее решение, но в Cotonti имена полей хранятся без префиска.
            $column_prefix = substr(static::$_primary_key, 0, strpos(static::$_primary_key, "_"));
            $column_prefix = (!empty($column_prefix)) ? $column_prefix.'_' : '';

            foreach($cot_extrafields[static::$_tbname] as $key => $field) {
                if(!array_key_exists($field['field_type'], $dbAdapter::$extraTypesMap)) continue;
                $desc = $field['field_description'];
                if(isset(cot::$L[$className.'_'.$field['field_name'].'_title'])) {
                    $desc = cot::$L[$className.'_'.$field['field_name'].'_title'];
                }
                $data = array(
                    'name'      => $column_prefix.$field['field_name'],
                    'type'      => $dbAdapter::$extraTypesMap[$field['field_type']],
//                    'length'    => '64',
                    'description' => $desc,
                    'nullable'  => ($field['field_required'] == 1) ? false : true,
                    'default'   => $field['field_default'],
                );

                $className::addFieldToAll($data, $className);
            }
        }
    }


    /**
     * Дополнительная инициализация
     * @param array $data
     */
    public function init(&$data = Array()) {}

    /**
     * @param Som_Model_Abstract|array $data данные для инициализации модели
     */
    public function __construct($data = null)
    {
        $pkey = static::primaryKey();

        // Инициализация полей
        $fields = static::fields();
        foreach ($fields as $name => $field) {
            if (!isset($field['link']) ||
                (in_array($field['link']['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL)) && !isset($field['link']['localKey'])) ){
                // Дефолтное значение
                $this->_data[$name] = isset($field['default']) ? $field['default'] : null;
            }
        }

        $this->init($data);

        // Заполняем существующие поля строго значениями из БД. Никаких сеттеров
        if (!is_null($data)) {
            foreach ($data as $key => $value) {
                if (in_array($key, static::getColumns())) {
                    $this->_data[$key] = $value;

                } elseif(!isset($fields[$key])) {
                    // Пробрасываем дополнительные значения
                    $this->_extraData[$key] = $value;
                }
            }
        }

        // Для существующих объектов грузим Многие ко многим (только id)
        if (isset($data[$pkey]) && $data[$pkey] > 0 && !empty($fields)) {
            foreach ($fields as $key => $field) {
                if (isset($field['link']) && in_array($field['link']['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                    $this->_linkData[$key] = static::$_db->loadXRef($field['link']["model"], $data[$pkey], $key);
                }
            }
        }
    }

    // ==== Методы для манипуляции с данными ====
    /**
     * @access public
     *
     * @param string $name
     * @param mixed $val
     *
     * @throws Exception
     * @return mixed
     */
    public function __set($name, $val) {
        $fields = static::fields();

        $link = false;
        // Проверка связей
        if (isset($fields[$name]) && $fields[$name]['type'] == 'link') {
            $link = (array)$fields[$name]['link'];
            $localkey = (!empty($link['localKey'])) ? $link['localKey'] : $name;
            $className = $link['model'];
        }

        // Set old data
        if(isset($fields[$name]) && !isset($this->_oldData[$name])) {
            if($link) {
                if (in_array($link['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                    $newData = $val;
                    $oldData = (isset($this->_linkData[$name])) ? $this->_linkData[$name] : null;
                    if(!is_array($newData)) $newData = array($newData);
                    if(!is_array($oldData)) $oldData = array($oldData);
                    if(!static::compareArrays($oldData, $newData)) $this->_oldData[$name] = $oldData;

                } elseif(in_array($link['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL))) {
                    if ($val instanceof Som_Model_Abstract) {
                        if($this->_data[$localkey] != $val->getId()) {
                            $this->_oldData[$name] = $this->_data[$localkey];
                        }
                    } else {
                        if($this->_data[$localkey] != $val) {
                            $this->_oldData[$name] = $this->_data[$localkey];
                        }
                    }
                }

            } elseif(in_array($fields[$name]['type'], array('datetime', 'date', 'timestamp')) ){
                if(strtotime($this->_data[$name]) != strtotime($val)){
                    $this->_oldData[$name] = $this->_data[$name];
                }

            } elseif($this->_data[$name] != $val) {
                $this->_oldData[$name] = $this->_data[$name];
            }

        }

        $methodName = 'set' . ucfirst($name);
        if ($methodName != 'setData' && method_exists($this, $methodName)) {
            return $this->$methodName($val);
        }

        // если передали объект, Один ко многим
        if ($val instanceof Som_Model_Abstract) {
            // Проверка типа
            if ($link) {
                if ($val instanceof $className) {
                    $val = $val->getId();
                } else {
                    throw new Exception("Тип переданного значения не соответствует пипу поля. Должно быть: $className");
                }
            } elseif (in_array($name, static::getColumns())) {
                throw new Exception("Связь для поля '{$name}' не найдена");
            }
        }


        // Если это связь
        if ($link) {
            // Один ко многим
            if (in_array($link['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL))) {
                $this->_data[$localkey] = $val;
                return true;
            }
            if (in_array($link['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                if (!is_array($val)) $val = array($val);
                $this->_linkData[$name] = array();
                foreach ($val as $value) {
                    // todo проверка типов
                    if ($value instanceof $className) {
                        $this->_linkData[$name][] = $value->getId();
                    } elseif ($value) {
                        $this->_linkData[$name][] = $value;
                    }
                }
            }
        }

        // input filter
        if (in_array($name, static::getColumns())) {
            // @todo общие типы данных, независимые от типа БД
            switch ($fields[$name]['type']) {
                case 'tinyint':
                case 'smallint':
                case 'mediumint':
                case 'int':
                case 'bigint':
                    if(!( is_numeric($val) && ($f = (float)$val) == (int)$f ))  $val = null;
                    break;

                case 'float':
                case 'double':
                case 'decimal':
                case 'numeric':
                    if(mb_strpos($val, ',') !== false) $val = str_replace(',', '.', $val);
                    $val = cot_import($val, 'DIRECT', 'NUM');
                    break;

                case 'bool':
                    if(!is_null($val)) $val = (bool)$val;
                    break;

                case 'varchar':
                case 'text':
                    $val = cot_import($val, 'DIRECT', 'HTM');
                    break;
            }
            $this->_data[$name] = $val;

        } elseif (!$link) {
            $this->_extraData[$name] = $val;
        }

        return true;
    }

    /**
     * @access public
     *
     * @param  $name
     *
     * @return null|mixed|Som_Model_Abstract
     */
    public function __get($name) {
        $methodName = 'get' . ucfirst($name);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        $fields = static::fields();

        // Проверка на наличие связей
        if (isset($fields[$name]) && $fields[$name]['type'] == 'link') {
            $list = (array)$fields[$name]['link'];

            /** @var Som_Model_Abstract $modelName */
            $modelName = $list['model'];
            $localkey = !empty($list['localKey']) ? $list['localKey'] : $name;

            // Один ко многим
            if (in_array($list['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL))) {
                if (empty($this->_data[$localkey])) return null;

                return $modelName::getById($this->_data[$localkey]);
            }

            // Многие ко многим
            if (in_array($list['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                if (!empty($this->_linkData[$name]) && is_array($this->_linkData[$name]) && count($this->_linkData[$name]) > 0) {
                    // ХЗ как лучше, find или в цикле getById (getById кешируется на время выполенния, но за раз много запрсов)
                    return $modelName::find(array(
                        array( $modelName::primaryKey(), $this->_linkData[$name] )
                    ), 0, 0, array($modelName::primaryKey()));
                } else {
                    return null;
                }

            }
        }

        if (isset($this->_data[$name])) {
            return $this->_data[$name];
        }
        if (isset($this->_extraData[$name])) {
            return $this->_extraData[$name];
        }

        return null;
    }

    /**
     * isset() handler for object properties.
     *
     * @param $column
     * @return bool
     */
    public function __isset($column) {
        $methodName = 'isset' . ucfirst($column);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        $fields = static::fields();

        // Проверка на наличие связей
        if (isset($fields[$column]) && $fields[$column]['type'] == 'link') {
            $list = (array)$fields[$column]['link'];

            $localkey = !empty($list['localKey']) ? $list['localKey'] : $column;
            // Один ко многим
            if (in_array($list['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL))) {
                return !empty($this->_data[$localkey]);
            }

            // Многие ко многим
            if (in_array($list['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                return !empty($this->_linkData[$column]);

            }
        }

        if (isset($this->_data[$column])) {
            return true;
        }

        if (isset($this->_extraData[$column])) {
            return true;
        }

        return false;
    }

    /**
     * unset() handler for object properties.
     *
     * @param string $column Column name
     * @return mixed|void
     */
    public function __unset($column) {
        $methodName = 'unset' . ucfirst($column);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        $fields = static::fields();

        // Проверка на наличие связей
        if (isset($fields[$column]) && $fields[$column]['type'] == 'link') {
            $list = (array)$fields[$column]['link'];

            $localkey = !empty($list['localKey']) ? $list['localKey'] : $column;
            // Один ко многим
            if (in_array($list['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL))) {
                unset($this->_data[$localkey]);
            }

            // Многие ко многим
            if (in_array($list['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                unset($this->_linkData[$column]);
            }
        }

        if (isset($this->_data[$column])) unset($this->_data[$column]);
        if (isset($this->_extraData[$column])) unset($this->_extraData[$column]);
    }

    /**
     * Возвращает элемент в виде массива
     * @return array
     */
    function toArray() {
        $data = $this->_data;
        return $data;
    }

    protected function beforeSetData(&$data){ return true; }

    /**
     * Заполняет модель данными
     *
     * @param array|Som_Model_Abstract $data
     * @param bool $safe безопасный режим
     *
     * @throws Exception
     * @return bool
     */
    public function setData($data, $safe = true) {
        $fields = static::fields();

        if ($this->beforeSetData($data)) {
            $class = get_class($this);
            if ($data instanceof $class)
                $data = $data->toArray();
            if (!is_array($data)) {
                throw new  Exception("Data must be an Array or instance of $class Class");
            }
            foreach ($data as $key => $value) {
                if ($safe && isset($fields[$key]['safe']) && $fields[$key]['safe']) {
                    if(!cot::$usr['isadmin'] && cot::$env['ext'] != 'admin'){
                        throw new Exception("Trying to write value «{$value}» to protected field «{$key}» of model «{$class}»");
                    }
                }
                if ($key != static::primaryKey()) {
                    if(is_string($value)) $value = trim($value);
                    $this->__set($key, $value);
                }
            }
        }

        $this->afterSetData($data);

        return true;
    }

    protected function afterSetData($data){}


    /**
     * Increment
     * @param  string|array $pair Поля для инкремента 'field2' или  array('field'=> 2, 'field2')
     *      В данном примере поле 'field2' будет увеличено на 1
     * @param array|string $conditions
     * @return bool
     */
    public function inc($pair = array(), $conditions = '') {
        if (!empty($pair)) {
            if(!is_array($pair)) $pair = array($pair);
            foreach ($pair as $field => $val){
                // Если передали просто имя поля, то увеличиваем его на 1
                if((is_int($field) || ctype_digit($field)) && is_string($val)){
                    $pair[$val] = 1;
                    unset($pair[$field]);
                }
            }
            foreach ($pair as $field => $val){
                $this->_data[$field] = $this->_data[$field] + $val;
            }
            $pkey = static::primaryKey();

            return static::$_db->inc(static::$_tbname, $pair, " {$pkey} = {$this->getId()} " .$conditions);
        }
    }

    /**
     * Decrement
     * @param string|array $pair Поля для декремента 'field2' или  array('field'=> 2, 'field2')
     *      В данном примере поле 'field2' будет уменьшено на 1
     * @param array|string $conditions
     *
     * @return mixed
     */
    public function dec($pair = array(), $conditions = array()) {
        if (!empty($pair)) {
            if(!is_array($pair)) $pair = array($pair);
            foreach ($pair as $field => $val){
                // Если передали просто имя поля, то уменьшаем его на 1
                if((is_int($field) || ctype_digit($field)) && is_string($val)){
                    $pair[$val] = 1;
                    unset($pair[$field]);
                }
            }
            foreach ($pair as $field => $val){
                $this->_data[$field] = $this->_data[$field] - $val;
            }
            $pkey = static::primaryKey();

            return static::$_db->dec(static::$_tbname, $pair, " {$pkey} = {$this->getId()} " .$conditions);
        }
    }
    // ==== /Методы для манипуляции с данными ====


    // ==== Методы для чтения и записи в БД ====
    /**
     * Retrieve existing object from database by primary key
     *
     * @param mixed $pk Primary key
     * @param bool $StaticCache Использовать кеш выборки?
     *
     * @return Som_Model_Abstract
     */
    public static function getById($pk, $StaticCache = true)
    {
        $pk = (int)$pk;
        if (!$pk) return null;

        $className = get_called_class();

        if ($StaticCache && isset(self::$_stCache[$className][$pk])) {
            return self::$_stCache[$className][$pk];
        }
        $pkey = static::primaryKey();
        $res  = static::fetch(array(array( $pkey, $pk )), 1);

        if ($StaticCache && !empty($res)) self::$_stCache[$className][$pk] = current($res);

        return ($res) ? current($res) : null;
    }

    /**
     * Получение единственного значения
     * @param array  $conditions
     * @param null   $order
     * @return Som_Model_Abstract
     */
    public static function fetchOne($conditions = array(), $order = null) {
        /** @var Som_Model_Abstract $className */
        $className = get_called_class();
        $res = $className::fetch($conditions, 1, 0, $order);
        if(!$res) return null;
        $res = current($res);
        self::$_stCache[$className][$res->getId()] = $res;
        return $res;
    }


    /**
     * Retrieve all existing objects from database
     *
     * @param mixed $conditions Numeric array of SQL WHERE conditions or a single
     *                           condition as a string
     * @param int $limit Maximum number of returned objects
     * @param int $offset Offset from where to begin returning objects
     * @param string $order Column name to order on
     *
     * @return Som_Model_Abstract[]
     */
    public static function find($conditions = array(), $limit = 0, $offset = 0, $order = '')
    {
        /** @var Som_Model_Abstract $className */
        $className = get_called_class();

        $res = null;
        $res = $className::fetch($conditions, $limit, $offset, $order);

        return $res;
    }

    /**
     * Retrieve a key => val list from the database.
     * @param array $conditions
     * @param int $limit
     * @param int $offset
     * @param string $order
     * @param string $field
     * @throws Exception
     * @return array
     */
    public static function keyValPairs($conditions = array(), $limit = 0, $offset = 0, $order = '', $field = null) {

        if(empty($field)) {
            $fields = static::fields();
            if(array_key_exists('title', $fields)) $field = 'title';
        }
        if(empty($field)) {
            throw new Exception('Field Name is missing');
        }

        if(empty($order)) $order = array(array($field, 'ASC'));

        /** @var Som_Model_Abstract $className */
        $className = get_called_class();

        $items = $className::find($conditions, $limit, $offset, $order);
        if(!$items) return array();

        $ret = array();
        foreach($items as $itemRow) {
            $ret[$itemRow->getId()] = $itemRow->{$field};
        }

        //return $_stCache[$key];
        return $ret;
    }

    /**
     *
     * @param string|array $conditions
     * @param int $limit Maximum number of returned objects
     * @param int $offset Offset from where to begin returning objects
     * @param string $order Column name to order on
     *
     * @return Som_Model_Abstract[]
     */
    protected static function fetch($conditions = array(), $limit = 0, $offset = 0, $order = '') {
        return static::$_db->fetch($conditions, $limit, $offset, $order);
    }

    protected function beforeSave(&$data = null) {
        $className = get_called_class();

        $return = true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.before.save') as $pl) {
            include $pl;
        }
        /* ===== */

        return $return;
    }

    /**
     * Save data
     *
     * @param Som_Model_Mapper_Abstract|array|null $data
     * @return int id of saved record
     */
    public function save($data = null) {
        $id = null;
        if ($this->beforeSave($data)) {
            if (is_array($data)) {
                $this->setData($data);
            }

            if (!$this->validate()) return false;

            if ($this->getId() === null) {

                // Добавить новый
                $id = $this->insert();
            } else {
                // Сохранить существующий
                $id = $this->getId();
                $this->update();
            }
        }

        if($id) {
            $this->afterSave();
            $this->_oldData = array();
        }

        return $id;
    }

    protected function afterSave() {
        $className = get_called_class();

        /* === Hook === */
        foreach (cot_getextplugins($className.'.after.save') as $pl) {
            include $pl;
        }
        /* ===== */
    }

    protected function beforeInsert() {
        $className = get_called_class();
        $fields = static::fields();

        // Заполнение волшебных полей
        if(array_key_exists('created',    $fields)) $this->_data['created']    = date('Y-m-d H:i:s', cot::$sys['now']);
        if(array_key_exists('created_by', $fields)) $this->_data['created_by'] = cot::$usr['id'];
        if(array_key_exists('updated',    $fields)) $this->_data['updated']    = date('Y-m-d H:i:s', cot::$sys['now']);
        if(array_key_exists('updated_by', $fields)) $this->_data['updated_by'] = cot::$usr['id'];

        $return = true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.before.insert') as $pl) {
            include $pl;
        }
        /* ===== */

        return $return;
    }

    /**
     * Create object
     * @return int created object id
     */
    protected final function insert() {
        if($this->beforeInsert()){
            $id = static::$_db->insert($this);

            if($id) $this->afterInsert();

            return $id;
        }else{
            return null;
        }

    }

    protected function afterInsert() {
        $className = get_called_class();

        /* === Hook === */
        foreach (cot_getextplugins($className.'.after.insert') as $pl) {
            include $pl;
        }
        /* ===== */
    }


    protected function beforeUpdate() {
        $className = get_called_class();
        $fields = static::fields();

        // Заполнение волшебных полей
        if(array_key_exists('updated',    $fields)) $this->_data['updated']    = date('Y-m-d H:i:s', cot::$sys['now']);
        if(array_key_exists('updated_by', $fields)) $this->_data['updated_by'] = cot::$usr['id'];

        $return = true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.before.update') as $pl) {
            include $pl;
        }
        /* ===== */

        return $return;
    }

    /**
     * Update object
     */
    protected final function update() {
        $className = get_called_class();

        if($this->beforeUpdate()){
            if (static::$_db->update($this) === 0) return 0;
            unset(self::$_stCache[$className][$this->getId()]);
            $this->afterUpdate();
        }else{
            return null;
        }
    }

    protected function afterUpdate() {
        $className = get_called_class();

        /* === Hook === */
        foreach (cot_getextplugins($className.'.after.update') as $pl) {
            include $pl;
        }
        /* ===== */
    }

    /**
     * Обновить набор элементов, соотвествующих условию
     *
     * @param array $data
     * @param mixed $condition
     * @return int
     * @throws Exception
     *
     */
    public static function updateRows($data, $condition = ''){
        if (empty($data)) {
            throw new Exception('$data is empty');
        }
        return static::$_db->update(static::$_tbname, $data, $condition);
    }

    protected function beforeDelete() {
        $className = get_called_class();

        $return = true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.before.delete') as $pl) {
            include $pl;
        }
        /* ===== */

        return $return;
    }

    /**
     * Delete object
     */
    public function delete() {
        $className = get_called_class();

        if (!$this->validateDelete() || !$this->beforeDelete()) return false;

        if(!empty($cot_extrafields[static::$_tbname])) {
            // Не очень хорошее решение, но в Cotonti имена полей хранятся без префиска.
            $column_prefix = substr(static::$_primary_key, 0, strpos(static::$_primary_key, "_"));
            $column_prefix = (!empty($column_prefix)) ? $column_prefix . '_' : '';

            foreach($cot_extrafields[static::$_tbname] as $key => $field) {
                cot_extrafield_unlinkfiles($this->_data[$column_prefix.$field['field_name']], $field);
            }
        }

        static::$_db->delete($this);
        unset(self::$_stCache[$className][$this->getId()]);

        $this->afterDelete();

        unset($this);

        return true;
    }

    protected function afterDelete() {
        $className = get_called_class();

        /* === Hook === */
        foreach (cot_getextplugins($className.'.after.delete') as $pl) {
            include $pl;
        }
        /* ===== */
    }

    /**
     * Получить количество элементов, соотвествующих условию
     * @param array $conditions
     *
     * @return int
     */
    public static function count($conditions = null)
    {
        return static::$_db->getCount(false, $conditions);
    }


    public static function tableExists($table)
    {
        return static::$_db->tableExists();
    }

    public static function createTable()
    {
        return static::$_db->createTable(false, static::fieldList());
    }

    public static function fieldExists($field)
    {
        // тут можно вернуть информацию из поля columns
        return static::$_db->fieldExists($field);
    }

    public static function createField($field)
    {
        return static::$_db->createField($field);
    }

    public static function alterField($old, $new = false)
    {
        return static::$_db->alterField($old, $new);
    }

    public static function deleteField($field)
    {
        return static::$_db->deleteField($field);
    }
    // ==== /Методы для чтения и записи  в БД ====


    // ==== Методы для работы с полями ====
    /**
     * Возвращает описание поля
     * @param $column
     * @return string
     */
    public static function fieldLabel($column) {
        $className = get_called_class();
        if(isset(cot::$L[$className.'_'.$column.'_title'])) return cot::$L[$className.'_'.$column.'_title'];

        $fields = static::fields();
        if (isset($fields[$column]['description'])) return $fields[$column]['description'];

        return '';
    }

    /**
     * @param $column
     * @return string
     * @deprecated
     */
    public static function getFieldLabel($column) {
        return static::fieldLabel($column);
    }

    /**
     * Возвращает все поля, включая дополнительные
     *
     * @param bool $real  получить поля напрямую из таблицы
     * @param bool $cache Использовать кеш периода выполнения
     *
     * @return array
     */
    public static function fields($real = false, $cache = true) {
        if ($real) return static::$_db->getFields(static::$_tbname);

        $className = get_called_class();

        if($cache && !empty(self::$fields[$className])) return self::$fields[$className];

        $extFields = array();
        if(!empty(self::$_extraFields[$className])) $extFields = self::$_extraFields[$className];

        self::$fields[$className] = array_merge(static::fieldList(), $extFields);

        return self::$fields[$className];
    }

    /**
     * @param $column
     * @return string
     * @deprecated
     */
    public static function getFields($real = false, $cache = true) {
        return static::fields($real, $cache);
    }

    /**
     * Получить поле по названию или по связи
     *   если полей с заданной связью несколько - вернет первое
     * @param $params
     * @return null
     */
    public static function field($params) {
        $fields = static::fields();

        if (is_string($params)) return (!empty($fields[$params])) ? $fields[$params] : null;
        // Если передали объект, надо искать связь
        if ($params instanceof Som_Model_Abstract) $params = array('model' => get_class($params));

        if (!empty($params['model'])) {
            if ($params['model'] instanceof Som_Model_Abstract) $params['model'] = get_class($params['model']);
            foreach ($fields as $fld) {
                if ($fld['type'] == 'link' && $fld['model'] == $params['model']) {
                    return $fld;
                }
            }
        }

        return null;
    }

    /**
     * Получить все поля из БД
     * Метод очень затратный по рессурсам. Кеширование на период выполнения необходимо
     *
     * @param bool $real  получить поля напрямую из таблицы
     * @param bool $cache Использовать кеш периода выполнения
     *
     * @return null|array
     */
    public static function getColumns($real = false, $cache = true) {

        if ($real) return static::$_db->getFields(static::$_tbname);

        $className = get_called_class();

        static $cols   = array();

        if($cache && !empty($cols[$className]))  return $cols[$className];

        $fields = static::fields($real, $cache);
        // Не включаем связи ко многим и, также, указывающие на другое поле
        $cols[$className] = array();
        foreach ($fields as $name => $field) {
            if (!isset($field['link']) ||
                (in_array($field['link']['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL)) && !isset($field['link']['localKey']))
            ) {
                $cols[$className][] = $name;
            }
        }

        return $cols[$className];
    }

    /**
     * Получить значение поля "Как есть"
     * @param $column
     * @return mixed
     */
    public function rawValue($column){
        $fields = static::fields();

        if(isset($fields[$column]) && $fields[$column]['type'] == 'link' && in_array($fields[$column]['link']['relation'],
                array(Som::TO_MANY, Som::TO_MANY_NULL))) {
            if(isset($this->_linkData[$column])) return $this->_linkData[$column];
            return null;

        } elseif (isset($this->_data[$column])) {
            return $this->_data[$column];
        }

        return null;
    }

    /**
     * Возвращает список названий полей, обязательных для заполнения
     * @return array
     */
    public function requiredFields() {
        $requiredFields = array();
        $validators   = $this->validators();
        foreach ($validators as $params) {
            $fieldList = $params[0];
            unset($params[0]);
            $fieldList = explode(",", $fieldList);
            $fieldList = array_map("trim", $fieldList);
            foreach ($fieldList as $field) {
                foreach ($params as $validators) {
                    if ($validators == 'required') $requiredFields[] = $field;
                }
            }
        }

        $fields = static::fields();
        foreach ($fields as $name => $field) {
            if ($name !== static::$_primary_key) {
                if (isset($field['nullable']) && !$field['nullable']) $requiredFields[] = $name;

                if (isset ($field['type']) && ($field['type'] == 'link')
                    && (in_array($field['link']['relation'], array(Som::TO_MANY, Som::TO_ONE))) ) {
                    $requiredFields[] = $name;
                }
            }
        }

        return $requiredFields;
    }

    /**
     * Является ли поле обязательным
     * @param string $field
     * @return bool
     */
    public function isRequired($field) {
        return in_array($field, $this->requiredFields());
    }

    /**
     * Add extrafield to model
     * @param array $params field params
     * @param string $donor Module which adds a new field. (Так удобднее отчслеживать кто и что добавило поле в модель)
     *      И для кодогенератора это знак, что поле дополнительное
     * @param bool $chekAdded throw Exeption if field already added
     * @throws Exception
     */
    public static function addFieldToAll($params, $donor, $chekAdded = true ){

        if(empty($params)){
            throw new Exception('Fields params are undefined');
        }

        if(empty($donor)){
            throw new Exception('$donor is undefined. Please write here module name which adds a new field.');
        }

        if(is_string($params)) $params = array('name' => $params);

        if(empty($params['name'])){
            throw new Exception('Field name is undefined');
        }

        if(array_key_exists($params['name'], static::fieldList())){
            throw new Exception("Field «{$params['name']}» already exists in model fields list");
        }

        $className = get_called_class();

        if($chekAdded && is_array(self::$_extraFields[$className]) && !empty(self::$_extraFields[$className][$params['name']]) &&
                                 array_key_exists($params['name'], self::$_extraFields[$className][$params['name']])){
            throw new Exception("Field «{$params['name']}» already added to model by «" .
                static::$_extraFields[$params['name']]['donor'] . "»");
        }

        $params['donor'] = $donor;
        self::$_extraFields[$className][$params['name']] = $params;

        self::$fields[$className] = array();
    }

    // ==== /Методы для работы с полями ====

    // ==== Методы для Валидации ====
    protected function validators(){ return array(); }

    public function getValidators($field = null)
    {
        if (!empty($field)) {
            if (!empty($this->validators[$field]) && count($this->validators[$field]) > 0) {
                return $this->validators[$field];
            } else {
                return null;
            }
        }

        return $this->validators;
    }

    /**
     * @param string $field
     * @param mixed callback, или string ('int', 'bool', ect) или массив вадидаторов этих типов
     *
     * @return $this
     * @todo проверка типов валидаторов
     */
    public function setValidator($field, $validators) {
        if (!is_array($validators)) $validators = array($validators);

        foreach ($validators as $val) {
            if (!isset($this->validators[$field]) || !in_array($val, $this->validators[$field])){
                $this->validators[$field][] = $val;
            }
        }

        return $this;
    }

    public function hasErrors($field = null) {
        if (!is_null($field)) {
            return (isset($this->_errors[$field]) && count($this->_errors[$field]));

        } elseif (count($this->_errors)) {
            return true;
        }

        return false;
    }

    /**
     * Проверяет модель на наличие ошибок
     * Этот метод полезно переопределять для создания проверок спецефичных для конкретной модели
     * @param array $validateFields
     * @return bool
     * @throws Exception
     * @todo дописать метод
     */
    public function validate($validateFields = null, $errorMessages = true) {
        $className = get_called_class();
        $validators = $this->validators();
        $fields = static::fields();

        foreach($this->requiredFields() as $field){
            $validators[] = array($field, 'required');
        }

        $m_validators = array();
        foreach ($validators as $params) {
            $fieldList = $params[0];
            unset($params[0]);
            $fieldList = explode(",", $fieldList);
            $fieldList = array_map("trim", $fieldList);

            foreach ($fieldList as $field) {
                foreach ($params as $validators) {
                    $m_validators[$field][] = $validators;
                }
            }
        }
        $this->validators = array_merge($this->validators, $m_validators);


        if (empty($validateFields)) {
            // Получить все поля модели
            $validateFields = static::getColumns();
            foreach ($fields as $name => $fld) {
                if (!in_array($name, $validateFields)) $validateFields[] = $name;
            }
        }

        foreach ($validateFields as $name) {
            $value = null;
            if (isset($this->_data[$name])) {
                $value = $this->_data[$name];

            } elseif (isset($fields[$name]) && $fields[$name]['type'] == 'link') {
                $list = (array)$fields[$name]['link'];
                // Многие ко многим
                if (in_array($list['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL)) &&
                                                                            !empty($this->_linkData[$name]) ) {
                    $value = $this->_linkData[$name];
                }
            }
            if (isset($this->validators[$name]) && count($this->validators[$name]) > 0) {
                foreach ($this->validators[$name] as $validator) {
                    // Проверка на Validator_Abstract
                    if ($validator instanceof Validator_Abstract) {
                        $validator->setModel($this);
                        $validator->setField($name);
                        if (!$validator->isValid($value)) {
                            $error = implode(', ', $validator->getMessages());
                            $this->_errors[$name][] = $error;
                        }

                    } elseif (is_callable($validator)) {
                        // Проверка на callback
                        try {
                            $res = call_user_func_array($validator, array($value));
                            if ($res !== true) $this->_errors[$name][] = $res;

                        } catch (Exception $e) {
                            throw new Exception("Не правильный CallBack validator для поля '{$name}'");

                        }

                    } elseif (is_string($validator)) {
                        switch (mb_strtolower($validator)) {
                            case 'required':
                                $this->_requiredFields[$name] = 1;
                                if (($value === '') || (is_null($value))) {
                                    $fieldName = $name;
                                    $tmp = static::fieldLabel($name);
                                    if(!empty($tmp)) $fieldName = $tmp;
                                    $error = (isset(cot::$L['field_required_'.$name])) ? cot::$L['field_required_'.$name] :
                                        cot::$L['field_required'].': '.$fieldName;
                                    $this->_errors[$name][] = $error;
                                }
                                break;
                            case 'int':
                            case 'integer':

                                break;

                        }
                    }
                }
            }
            // Проверка на соотвествие типу
            if (!empty($fields[$name])) {
                switch (mb_strtolower($fields[$name]['type'])) {
                    case 'int':
                    case 'integer':

                        break;

                }
            }
        }

        // Системные сообщения об ошибках
        if(count($this->_errors) > 0 && $errorMessages) {
            foreach($this->_errors as $name => $errors) {
                if(!empty($errors)) {
                    foreach($errors as $errorRow) {
                        cot_error($errorRow, $name);
                    }
                }
            }
        }

        $return = count($this->_errors) ? false : true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.validate') as $pl) {
            include $pl;
        }
        /* ===== */

        return $return;
    }

    /**
     * Провека на возможность удаления
     * Этот метод полезно переопределять для создания проверок спецефичных для конкретной модели
     * @return bool
     */
    public function validateDelete($errorMessages = true) {
        $className = get_called_class();

        $return = true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.validate.delete') as $pl) {
            include $pl;
        }
        /* ===== */

        return $return;
    }
    // ==== /Методы для Валидации ====

    /**
     * Adapter Factory
     * @param string $db connection name
     * @throws Exception
     * @return Som_Model_Mapper_Abstract
     */
    public static function getAdapter($db = 'db') {
        $className = get_called_class();

        if (!empty(static::$_tbname) && isset(static::$_dbtype)) {
            return Som::getAdapter($db, array(
                        "class" => get_called_class(),
                        "tbname" => static::$_tbname,
                        "pkey" => static::primaryKey(),
                    ));
        } else {
            throw new Exception("Wrong model parameters: $className");
        }
    }

    /**
     * Get Table Name
     * @return string
     */
    public static function tableName(){
        return static::$_tbname;
    }

    /**
     * Get Table Name
     * @deprecated
     * @return string
     */
    public static function getTableName(){
        return static::tableName();
    }

    /**
     * Получить настройки DB
     * @return array
     */
    public static function getDbConfig()
    {
        return array(
            "dbtype" => static::$_dbtype,
            "tbname" => static::$_tbname,
            "pkey" => static::primaryKey()
        );
    }

    /**
     * Get Primary Key
     * @return int
     */
    public function getId() {
        $pkey = static::primaryKey();
        if (empty($this->_data[$pkey])) return null;

        return $this->_data[$pkey];
    }

    /**
     * Returns primary key column name. Defaults to 'id' if none was set.
     *
     * @return string
     */
    public static function primaryKey() {
        return isset(static::$_primary_key) ? static::$_primary_key : 'id';
    }

    function __toString() {
        return $this->getId();
    }


    protected static function compareArrays($arrayA , $arrayB) {
        $A = array();
        if(!empty($arrayA)) {
            foreach($arrayA as $key => $val) {
                if($val instanceof Som_Model_Abstract) {
                    $A[] = $val->getId();
                } else {
                    $A[] = $val;
                }
            }
        }

        $B = array();
        if(!empty($arrayB)) {
            foreach($arrayB as $key => $val) {
                if($val instanceof Som_Model_Abstract) {
                    $B[] = $val->getId();
                } else {
                    $B[] = $val;
                }
            }
        }

        if(count($A) > count($B)){
            $diff = array_diff($A,$B);
        } else {
            $diff = array_diff($B,$A);
        }

        return (!empty($diff)?false:true);
    }

    /* =========================== */
    /**
     * Конфиг модели. Информация обо всех полях
     * @return array
     */
    public static function fieldList() { return array(); }
}