<?php

/**
 *
 * @author  Mankov
 * @author: Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 * @version 1.2
 *
 * @static string $_dbtype
 * @static string $_tbname
 * @static Som_Model_Mapper_Abstract $_db;
 * @static  string $_primary_key
 * @todo    Модели наблюдатели. При перед удалением модели другие модели оповещаются об этом и могут запретить удаление
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
     * @var array Список полей, описанный в моделе и/или получаемый из полей БД. С типами данных.
     */
    protected $fields = array();

    /**
     * Model extrafields. Поля присутсвубщие в таблице, но не описанные в fieldList().
     * Они могут быть созданы другими модулями
     * @var array
     */
    public static $_extraFields = array();

    protected $_e;

    protected $validators = array();


    /**
     * Static constructor
     * Модель не проверяет наличие таблицы и всех полей, а наивно доверяет данным из метода fieldList()
     */
    public static function __init($db = 'db'){
        static::$_dbtype = 'mysql';
        static::$_db = static::getMapper($db);
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
        $this->fields = static::fieldList();
        $this->fields = array_merge($this->fields, static::$_extraFields);
        foreach ($this->fields as $name => $field) {
            if (!isset($field['link']) ||
                (in_array($field['link']['relation'], array('toone', 'toonenull')) && !isset($field['link']['localKey'])) ){
                // Дефолтное значение
                $this->_data[$name] = isset($field['default']) ? $field['default'] : null;
            }
        }

        $this->init($data);

        // Заполняем существующие поля строго значениями из БД. Никаких сеттеров
        if (!is_null($data)) {
            foreach ($data as $key => $value) {
                if (in_array($key, static::getColumns())) $this->_data[$key] = $value;
            }
        }

        // Для существующих объектов грузим Многие ко многим (только id)
        if (isset($data[$pkey]) && $data[$pkey] > 0 && !empty($this->fields)) {
            foreach ($this->fields as $key => $field) {
                if (isset($field['link']) && in_array($field['link']['relation'], array('tomanynull', 'tomany'))) {
                    $this->fields[$key]['data'] = static::$_db->loadXRef($field['link']["model"], $data[$pkey], $key);
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
    public function __set($name, $val)
    {
        $link = false;
        // Проверка связей
        if (isset($this->fields[$name]) && $this->fields[$name]['type'] == 'link') {
            $link = (array)$this->fields[$name]['link'];
            $localkey = (!empty($link['localKey'])) ? $link['localKey'] : $name;
            $className = $link['model'];
        }

        $methodName = 'set' . ucfirst($name);
        if (method_exists($this, $methodName)) {
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
                    exit();
                }
            } elseif (in_array($name, static::getColumns())) {
                throw new Exception("Связь для поля '{$name}' не найдена");
                exit();
            }
        }


        // Если это связь
        if ($link) {
            // Один ко многим
            if (in_array($link['relation'], array('toonenull', 'toone'))) {
                $this->_data[$localkey] = $val;

                return;
            }
            if (in_array($link['relation'], array('tomanynull', 'tomany'))) {
                if (!is_array($val)) $val = array($val);
                $this->fields[$name]['data'] = array();
                foreach ($val as $value) {
                    // todo проверка типов
                    if ($value instanceof $className) {
                        $this->fields[$name]['data'][] = $value->getId();
                    } else {
                        $this->fields[$name]['data'][] = $value;
                    }
                }
            }
        }

        // input filter
        if (in_array($name, static::getColumns())) {
            // @todo общие типы данных, независимые от типа БД
            switch ($this->fields[$name]['type']) {
                case 'tinyint':
                case 'smallint':
                case 'mediumint':
                case 'int':
                case 'bigint':
                    if(!( is_int($val) || ctype_digit($val) )) $val = null;
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
            }
            $this->_data[$name] = $val;
        }
    }

    /**
     * @access public
     *
     * @param  $name
     *
     * @return null|mixed|Som_Model_Abstract
     */
    public function __get($name)
    {
        $methodName = 'get' . ucfirst($name);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }


        // Проверка на наличие связей
        if (isset($this->fields[$name]) && $this->fields[$name]['type'] == 'link') {
            $list = (array)$this->fields[$name]['link'];

            /** @var Som_Model_Abstract $modelName */
            $modelName = $list['model'];
            $localkey = !empty($list['localKey']) ? $list['localKey'] : $name;

            // Один ко многим
            if (in_array($list['relation'], array('toonenull', 'toone'))) {
                if (empty($this->_data[$localkey]))
                    return null;

                return $modelName::getById($this->_data[$localkey]);
            }

            // Многие ко многим
            if (in_array($list['relation'], array('tomanynull', 'tomany'))) {
                if (!empty($this->fields[$name]['data'])) {
                    // ХЗ как лучше, find или в цикле getById (getById кешируется на время выполенния, но за раз много запрсов)
                    return $modelName::find(array(
                        array(
                            $modelName::primaryKey(),
                            $this->fields[$name]['data']
                        )
                    ));
                } else {
                    return null;
                }

            }
        }

        return isset($this->_data[$name]) ? $this->_data[$name] : null;
    }

    /**
     * isset() handler for object properties.
     *
     * @param $column
     *
     * @return bool
     */
    public function __isset($column)
    {
        $methodName = 'isset' . ucfirst($column);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        // Проверка на наличие связей
        if (isset($this->fields[$column]) && $this->fields[$column]['type'] == 'link') {
            $list = (array)$this->fields[$column]['link'];

            $localkey = !empty($list['localKey']) ? $list['localKey'] : $column;
            // Один ко многим
            if (in_array($list['relation'], array('toonenull', 'toone'))) {
                return !empty($this->_data[$localkey]);
            }

            // Многие ко многим
            if (in_array($list['relation'], array('tomanynull', 'tomany'))) {
                return !empty($this->fields[$column]['data']);

            }
        }

        return isset($this->_data[$column]);
    }

    /**
     * unset() handler for object properties.
     *
     * @param string $column Column name
     * @return mixed|void
     */
    public function __unset($column)
    {
        $methodName = 'unset' . ucfirst($column);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        // Проверка на наличие связей
        if (isset($this->fields[$column]) && $this->fields[$column]['type'] == 'link') {
            $list = (array)$this->fields[$column]['link'];

            $localkey = !empty($list['localKey']) ? $list['localKey'] : $column;
            // Один ко многим
            if (in_array($list['relation'], array('toonenull', 'toone'))) {
                unset($this->_data[$localkey]);
            }

            // Многие ко многим
            if (in_array($list['relation'], array('tomanynull', 'tomany'))) {
                unset($this->fields[$column]['data']);

            }
        }


        if (isset($this->_data[$column]))
            unset($this->_data[$column]);
    }

    /**
     * Возвращает элемент в виде массива
     *
     * @return array
     */
    function toArray()
    {
        $data = $this->_data;
        return $data;
    }

    protected function beforeSetData($data){ return true; }

    /**
     * Заполняет модель данными
     *
     * @param array|Som_Model_Abstract $data
     * @param bool $safe безопасный режим
     *
     * @throws Exception
     * @return bool
     */
    public function setData($data, $safe=true)
    {
        if ($this->beforeSetData($data)) {
            $class = get_class($this);
            if ($data instanceof $class)
                $data = $data->toArray();
            if (!is_array($data)) {
                throw new  Exception("Data must be an Array or instance of $class Class");
            }
            foreach ($data as $key => $value) {
                if ($safe && isset($this->fields[$key]['safe']) && $this->fields[$key]['safe']){
                    if(!cot::$usr['isadmin'] && cot::$env['ext'] != 'admin'){
                        throw new Exception("Trying to write value «{$value}» to protected field «{$key}» of model «{$class}»");
                    }
                }
                $this->__set($key, $value);
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

            return static::$_db->inc(static::$_tbname, $pair,
                " {$this->primaryKey()} = {$this->getId()} " .$conditions);
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

            return static::$_db->dec(static::$_tbname, $pair,
                " {$this->primaryKey()} = {$this->getId()} " .$conditions);
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
     *
     * @param string|array $conditions
     * @param int $limit Maximum number of returned objects
     * @param int $offset Offset from where to begin returning objects
     * @param string $order Column name to order on
     *
     * @return Som_Model_Abstract[]
     */
    protected static function fetch($conditions = array(), $limit = 0, $offset = 0, $order = '')
    {
        return static::$_db->fetch($conditions, $limit, $offset, $order);
    }

    protected function beforeSave(&$data = null){ return true; }

    /**
     * Save data
     *
     * @param Som_Model_Mapper_Abstract|array|null $data

     * @return int id of saved record
     */
    public function save($data = null)
    {
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

        if($id) $this->afterSave();

        return $id;
    }

    protected function afterSave(){}

    protected function beforeInsert(){ return true; }

    /**
     * Создать объект
     * @return int id Созданного объекта
     */
    protected final function insert(){
        if($this->beforeInsert()){
            $id = static::$_db->insert($this);

            if($id) $this->afterInsert();

            return $id;
        }else{
            return null;
        }

    }

    protected function afterInsert(){ }


    protected function beforeUpdate(){ return true; }

    /**
     * Обновить объект
     */
    protected final function update(){
        $className = get_called_class();

        if($this->beforeUpdate()){
            if (static::$_db->update($this) === 0) return 0;
            unset(self::$_stCache[$className][$this->getId()]);
            $this->afterUpdate();
        }else{
            return null;
        }
    }

    protected function afterUpdate(){ }

    protected function beforeDelete(){ return true; }

    /**
     * @access public
     */
    public function delete()
    {
        $className = get_called_class();

        if (!$this->validateDelete() || !$this->beforeDelete()) return false;

        static::$_db->delete($this);
        unset(self::$_stCache[$className][$this->getId()]);

        $this->afterDelete();

        unset($this);

        return true;
    }

    protected function afterDelete(){ return true; }

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
    public function getFieldLabel($column)
    {
        if (isset($this->fields[$column]['description'])) return $this->fields[$column]['description'];

        return '';
    }

    /**
     * Возвращает все поля, включая дополнительные
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Получить поле по названию или по связи
     *   если полей с заданной связью несколько - вернет первое
     * @param $params
     * @return null
     */
    public function getField($params)
    {
        if (is_string($params)) return (!empty($this->fields[$params])) ? $this->fields[$params] : null;
        // Если передали объект, надо искать связь
        if (is_a($params, 'Som_Model_Abstract')) $params = array('model' => get_class($params));

        if (!empty($params['model'])) {
            if (is_a($params['model'], 'Som_Model_Abstract')) $params['model'] = get_class($params['model']);
            foreach ($this->fields as $fld) {
                if ($fld['type'] == 'link' && $fld['model'] == $params['model']) {
                    return $fld;
                }
            }
        }

        return null;
    }

    /**
     * Получить все поля из БД
     *
     * @param bool $real получить поля напрямую из таблицы
     * @return array|null
     */
    public static function getColumns($real = false) {

        if($real) return static::$_db->getFields(static::$_tbname);

        $cols = array();
        $fields = array_merge(static::fieldList(), static::$_extraFields);
        // Не включаем связи ко многим и, также, указывающие на другое поле
        foreach ($fields as $name => $field) {
            if (!isset($field['link']) ||
                (in_array($field['link']['relation'], array('toone', 'toonenull')) && !isset($field['link']['localKey'])) ){

                $cols[] = $name;
            }
        }
        return $cols;
    }

    /**
     * Получить значение поля "Как есть"
     * @param $column
     * @return mixed
     */
    public function rawValue($column){
        if(isset($this->_data[$column])) return $this->_data[$column];
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

        $fields = $this->getFields();
        foreach ($fields as $name => $field) {
            if ($name !== static::$_primary_key) {
                if (isset($field['nullable']) && !$field['nullable']) $requiredFields[] = $name;

                if (isset ($field['type']) && ($field['type'] == 'link')
                    && (in_array($field['link']['relation'], array('tomany', 'toone'))) ) {
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

        if($chekAdded && array_key_exists($params['name'], static::$_extraFields)){
            throw new Exception("Field «{$params['name']}» already added to model by «".
                static::$_extraFields[$params['name']]['donor']."»");
        }

        $params['donor'] = $donor;
        static::$_extraFields[$params['name']] = $params;
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
    public function setValidator($field, $validators)
    {
        if (!is_array($validators))
            $validators = array($validators);

        foreach ($validators as $val) {
            if (!in_array($val, $this->validators[$field]))
                $this->validators[$field][] = $val;
        }

        return $this;
    }

    public function hasErrors()
    {

    }

    /**
     * Проверяет модель на наличие ошибок
     * Этот метод полезно переопределять для создания проверок спецефичных для конкретной модели
     * @param array $fields
     *
     * @return boolean
     * @todo дописать метод
     */
    public function validate($fields = null)
    {
        $validators = $this->validators();

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


        if (empty($fields)) {
            // Получить все поля модели
            $fields = static::getColumns();
            foreach ($this->fields as $name => $fld) {
                if (!in_array($name, $fields))
                    $fields[] = $name;
            }
        }

        foreach ($fields as $name) {
            $value = null;
            if (isset($this->_data[$name])) {
                $value = $this->_data[$name];
            } elseif (isset($this->fields[$name]) && $this->fields[$name]['type'] == 'link') {
                $list = (array)$this->fields[$name]['link'];
                // Многие ко многим
                if (in_array($list['relation'], array('tomanynull', 'tomany')) && !empty($this->fields[$name]['data'])
                ) {
                    $value = $this->fields[$name]['data'];
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
                            if ($res !== true)
                                $this->_errors[$name][] = $res;
                        } catch (Exception $e) {
                            throw new Exception("Не правильный CallBack validator для поля '{$name}'");
                            return false;
                        }
                    } elseif (is_string($validator)) {
                        switch (mb_strtolower($validator)) {
                            case 'required':
                                $this->_requiredFields[$name] = 1;
                                if ($value == '') {
                                    $error = 'Обязательное для заполнения';
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
            if (!empty($this->fields[$name])) {
                switch (mb_strtolower($this->fields[$name]['type'])) {
                    case 'int':
                    case 'integer':

                        break;

                }
            }
        }

        return count($this->_errors) ? false : true;
    }

    /**
     * Провека на возможность удаления
     * Этот метод полезно перелпределять для создания проверок спецефичных для конкретной модели
     * @return bool
     * @todo получить наблюдателей и спросить разрешения на удаление объекта!
     */
    public function validateDelete()
    {
        return true;
    }
    // ==== /Методы для Валидации ====

    /**
     * Фабрика мапперов
     * @param string $db connection name
     * @throws Exception
     * @return Som_Model_Mapper_Abstract
     */
    public static function getMapper($db = 'db')
    {
        $className = get_called_class();

        if (!empty(static::$_tbname) && isset(static::$_dbtype)) {
            return Som_Model_Mapper_Manager::getMapper(array(
                "class" => get_called_class(),
                "tbname" => static::$_tbname,
                "pkey" => static::primaryKey(),
            ), $db);
        } else {
            throw new Exception("Не верно заданы параметры модели: $className");
        }
    }

    /**
     * Get Table Name
     * @return string
     */
    public static function getTableName(){
        return static::$_tbname;
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
    public static function primaryKey(){
        return isset(static::$_primary_key) ? static::$_primary_key : 'id';
    }

    function __toString()
    {
        return $this->getId();
    }

    /* =========================== */
    /**
     * Конфиг модели. Информация обо всех полях
     * @return array
     */
    public static function fieldList()
    {
        return array();
    }
}