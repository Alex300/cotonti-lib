<?php
/**
 *
 */
abstract class Som_Model_Mapper_Abstract
{
    /**
     * @var PDO[] connections registry
     */
    protected static $connections = array();

    /**
     * @var string Символ quoted identifier
     */
    protected $tableQuote = '';

    /**
     * @var array
     */
    protected $_dbinfo = null;

    /**
     * Number of rows affected by the most recent query
     * @var int
     */
    protected $_affected_rows = 0;

    /**
     * @var PDO ссылка на объект БД
     */
    protected $_adapter = null;

    /**
     * ExtraFields types to SQL types
     * @var array
     */
    public static $extraTypesMap = array(
        'select'        => 'varchar',
		'radio'         => 'varchar',
		'range'         => 'varchar',
		'file'          => 'varchar',
		'input'         => 'varchar',
		'inputint'      => 'int',
		'currency'      => 'numeric',
		'double'        => 'double',
		'checklistbox'  => 'text',
		'textarea'      => 'text',
		'checkbox'      => 'tinyint',
		'datetime'      => 'int',
		'country'       => 'char',
		'filesize'      => 'int'
    );

    /**
     * @param $dbinfo
     * @param string $dbc
     */
    function __construct($dbinfo, $dbc = 'db'){
        $this->_dbinfo = $dbinfo;
        $this->_adapter = static::connect($dbc);
    }

    /**
     * Connect to Data Base
     * @param $dbc
     * @return PDO
     */
    protected static function connect($dbc) {
        if (empty(self::$connections[$dbc])) {

            // Connect to DB
            if($dbc == 'db'){
                // Default cotonti connection
                self::$connections[$dbc] = cot::$db;
            }else{
                // Альтернативное соединение из конфига
                $dbc_port = empty(cot::$cfg[$dbc]['port']) ? '' : ';port=' . cot::$cfg[$dbc]['port'];
                $dsn = cot::$cfg[$dbc]['adapter'] . ':host=' . cot::$cfg[$dbc]['host'] . $dbc_port .
                    ';dbname=' . cot::$cfg[$dbc]['dbname'];
                self::$connections[$dbc] = new PDO($dsn, cot::$cfg[$dbc]['username'], cot::$cfg[$dbc]['password']);
            }
        }

        return self::$connections[$dbc];
    }

    /**
     * Закрываем соединение с БД
     * @param $dbc
     */
    public static function closeConnect($dbc){
        unset(self::$connections[$dbc]);
    }

    /**
     * Получить существующие таблиуы
     *  Например: SHOW TABLES для MySql
     * @param string $schema
     * @return array
     */
    public abstract function tables($schema = '');

    /**
     * Получить существующие поля
     * @param string $table
     * @return array
     */
    public abstract function getFields($table = '');

    /**
     * Проверить существование таблицы
     * @param string $table
     * @return bool
     */
    public abstract function tableExists($table = '');

    /**
     * Создать таблицу
     * @param string $table
     * @param array $fields
     * @return mixed
     */
    public abstract function createTable($table = '', $fields = array());

    /**
    * Создать таблицу
    * @param string $tablename
    * @param string $newtablename
     * @return mixed
    */
    public abstract function renameTable($tablename= '',$newtablename = '' );
    /**
     * Проверить существование поля
     * @param $field_name
     * @param string $table
     * @return bool
     */
    public abstract function fieldExists($field_name, $table = '');

    /**
     * Изменить существющее поле
     * @param array $old
     * @param array|bool $new
     * @return bool
     */
    public abstract function alterField($old, $new = false);

    /**
     * Удалить поля
     * @param string $field_name
     * @return bool
     */
    public abstract function deleteField($field_name);


    /**
     * Returns the column descriptions for a table.
     *
     * The return value is an associative array keyed by the column name,
     * as returned by the RDBMS.
     *
     * The value of each array element is an associative array
     * with the following keys:
     *
     * SCHEMA_NAME => string; name of database or schema
     * TABLE_NAME  => string;
     * COLUMN_NAME => string; column name
     * COLUMN_POSITION => number; ordinal position of column in table
     * DATA_TYPE   => string; SQL datatype name of column
     * DEFAULT     => string; default expression of column, null if none
     * NULLABLE    => boolean; true if column can have nulls
     * LENGTH      => number; length of CHAR/VARCHAR
     * SCALE       => number; scale of NUMERIC/DECIMAL
     * PRECISION   => number; precision of NUMERIC/DECIMAL
     * UNSIGNED    => boolean; unsigned property of an integer type
     * PRIMARY     => boolean; true if column is part of the primary key
     * PRIMARY_POSITION => integer; position of column in primary key
     *
     * @param string $tableName
     * @param string $schemaName OPTIONAL
     * @return array
     */
    abstract public function describeTable($tableName, $schemaName = null);


    /**
     * 1) If called with one parameter:
     * Works like PDO::query()
     * Executes an SQL statement in a single function call, returning the result set (if any) returned by the statement as a PDOStatement object.
     * 2) If called with second parameter as array of input parameter bindings:
     * Works like PDO::prepare()->execute()
     * Prepares an SQL statement and executes it.
     * @see http://www.php.net/manual/en/pdo.query.php
     * @see http://www.php.net/manual/en/pdo.prepare.php
     * @param string $query The SQL statement to prepare and execute.
     * @param array $parameters An array of values to be binded as input parameters to the query. PHP int parameters will beconsidered as PDO::PARAM_INT, others as PDO::PARAM_STR.
     * @return PDOStatement
     */
    public function query($query, $parameters = array())
    {

        if (!is_array($parameters)) $parameters = array($parameters);

//        try
//        {
        if (count($parameters) > 0) {
            $result = $this->_adapter->prepare($query);
            $this->_bindParams($result, $parameters);

            if ($result->execute() === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }
        } else {
            $result = $this->_adapter->query($query);
            if ($result === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }
        }
//        }
//        catch (PDOException $err)
//        {
//            if ($this->_parseError($err, $err_code, $err_message))
//            {
//                cot_diefatal('SQL error ' . $err_code . ': ' . $err_message);
//            }
//        }
        // We use PDO::FETCH_ASSOC by default to save memory
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $this->_affected_rows = $result->rowCount();
        return $result;
    }


    /**
     * Получить записи
     * @access public
     *
     * @param array $conditions
     * @param  int $limit
     * @param  int $offset
     * @param array|string $order
     * @param array $columns
     * @return bool|Som_Model_Abstract[]
     * @throws Exception
     */
    public function fetch($conditions = array(), $limit = 0, $offset = 0, $order = '', $columns = Array())
    {
        $tq = $this->tableQuote;
        $table = $this->_dbinfo['tbname'];
        /** @var Som_Model_Abstract $model_name */
        $model_name = $this->_dbinfo['class'];

        $joins = array();
        $addColumns = array();

        if (empty($columns))
            $calssCols = $model_name::getColumns();
        else
            $calssCols = $columns;

        if (!empty($model_name::$fetchJoins)) $addJoins = $model_name::$fetchJoins;
        if (!empty($model_name::$fetchColumns)) $addColumns = $model_name::$fetchColumns;

        $columns = array();
        foreach ($calssCols as $col) {
            $columns[] = "{$tq}$table{$tq}.{$tq}$col{$tq}";
        }
        if (!empty($addColumns)) $columns = array_merge($columns, $addColumns);
        $columns = implode(', ', $columns);

        list($where, $params, $joins) = $this->parseConditions($conditions);
        if (!empty($addJoins)) $joins = array_merge($joins, $addJoins);
        $joins = implode("\n", $joins);


        if (!empty($order)) $order = $this->parseOrder($order);
        $order = ($order) ? "ORDER BY $order" : '';
        $limit = ($limit) ? "LIMIT $limit OFFSET $offset" : '';

        $objects = array();
        $sql = "SELECT $columns\n FROM {$tq}$table{$tq}\n $joins\n $where\n $order\n $limit";

        $res = $this->query($sql, $params);
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $obj = new $model_name($row);
            $objects[$row[$model_name::primaryKey()]] = $obj;
        }

        if ($res->closeCursor() === false) {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }
        return (count($objects) > 0) ? $objects : null;
    }

    /**
     * Получить количество строк, удовлетворяющих условию
     * @access public
     * @param string $table
     * @param $conditions
     *
     * @throws Exception
     * @return int
     */
    public final function getCount($table = '', $conditions)
    {
        $tq = $this->tableQuote;
        if (empty($table)){
            $table = $this->_dbinfo['tbname'];
            if(!empty($this->_dbinfo['class'])){
                /** @var Som_Model_Abstract $model_name */
                $model_name = $this->_dbinfo['class'];
                if (!empty($model_name::$fetchJoins)) $addJoins = $model_name::$fetchJoins;
            }
        }

        list($where, $params, $joins) = $this->parseConditions($conditions);
        if (!empty($addJoins)) $joins = array_merge($joins, $addJoins);
        $joins = implode("\n", $joins);

        $sql = "SELECT COUNT(*) FROM {$tq}$table{$tq}\n $joins\n $where\n";
        $res = $this->query($sql, $params)->fetchColumn();
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }
        return intval($res);
    }

    /**
     * Performs SQL INSERT on simple data array. Array keys must match table keys, optionally you can specify
     * key prefix as third parameter. Strings get quoted and escaped automatically.
     * Ints and floats must be typecasted.
     * You can use special values in the array:
     * - PHP NULL => SQL NULL
     * - 'NOW()' => SQL NOW()
     * Performs single row INSERT if $data is an associative array,
     * performs multi-row INSERT if $data is a 2D array (numeric => assoc)
     *
     * @param string|\Som_Model_Abstract $table_name Table name
     * @param array|bool $data Associative or 2D array containing data for insertion.
     * @param bool $insert_null Insert SQL NULL for empty values rather than ignoring them.
     * @param bool $ignore Ignore duplicate key errors on insert
     * @param array $update_fields List of fields to be updated with ON DUPLICATE KEY UPDATE
     * @return int The number of affected records
     * @throws Exception
     */
    public function insert($table_name, $data = false, $insert_null = false, $ignore = false, $update_fields = array()) {
        $model = null;
        $tq = $this->tableQuote;

        $pkey = '';
        if ($table_name instanceof Som_Model_Abstract) {
            $model = $table_name;
            $table_name = $this->_dbinfo['tbname'];
            $pkey = $this->_dbinfo['pkey'];

            if (empty($data)) $data = $model->toArray();
        }

        if (!is_array($data)) return 0;

        $keys = '';
        $vals = '';
        // Check the array type
        $arr_keys = array_keys($data);
        $multiline = is_numeric($arr_keys[0]);
        // Build the query
        if ($multiline) {
            $rowset = & $data;
        } else {
            $rowset = array($data);
        }
        $keys_built = false;
        $cnt = count($rowset);
        for ($i = 0; $i < $cnt; $i++) {
            $vals .= ($i > 0) ? ',(' : '(';
            $j = 0;
            if (is_array($rowset[$i])) {
                foreach ($rowset[$i] as $key => $val) {
                    if (is_null($val) && !$insert_null) {
                        continue;
                    }

                    if ($j > 0) $vals .= ',';
                    if (!$keys_built) {
                        if ($j > 0) $keys .= ',';
                        $keys .= "{$tq}$key{$tq}";
                    }
                    if (is_null($val) && $insert_null) {
                        $vals .= 'NULL';
                    } elseif ($val === 'NOW()') {
                        $vals .= 'NOW()';
                    } elseif (is_int($val) || is_float($val)) {
                        $vals .= $val;
                    } else {
                        $vals .= static::quote($val);
                    }
                    $j++;
                }
            }
            $vals .= ')';
            $keys_built = true;
        }
        if (!empty($keys) && !empty($vals)) {
            $ignore = $ignore ? 'IGNORE' : '';
            $query = "INSERT $ignore INTO {$tq}$table_name{$tq} ($keys) VALUES $vals";
            if (count($update_fields) > 0) {
                $query .= ' ON DUPLICATE KEY UPDATE';
                $j = 0;
                foreach ($update_fields as $key) {
                    if ($j > 0) $query .= ',';
                    $query .= " {$tq}$key{$tq} = VALUES({$tq}$key{$tq})";
                    $j++;
                }
            }
//            $this->_startTimer();
            /**
             * @var PDOStatement $res ;
             */
            $res = $this->_adapter->query($query);
            if ($res === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }
//            $this->_stopTimer($query);

            $id = $this->lastInsertId($table_name, $pkey);

            if ($id > 0 && !empty($model)) {
                $data[$pkey] = $id;
                $fields = $model::fields();
                $model->{$pkey} = $id;
                if (!empty($fields)) {
                    foreach ($fields as $fieldName => $field) {
                        if (isset($field['link']) && in_array($field['link']['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                            $fieldData = $model->rawValue($fieldName);
                            if (empty($fieldData)) $fieldData = null;
                            $this->saveXRef($field['link']["model"], $data[$pkey], $fieldData, $fieldName);
                        }
                    }
                }
            }

            return $id;
        }
        return false;
    }

    /**
     * @param string $table_name Опционально. Нужно для PosgreSql
     * @param string $pkey Опционально. Нужно для PosgreSql
     * @return string
     */
    public function lastInsertId($table_name = '', $pkey = '')
    {
        return $this->_adapter->lastInsertId($table_name, $pkey);
    }

    /**
     * Performs SQL UPDATE with simple data array. Array keys must match table keys, optionally you can specify
     * key prefix as fourth parameter. Strings get quoted and escaped automatically.
     * Ints and floats must be typecasted.
     * You can use special values in the array:
     * - PHP NULL => SQL NULL
     * - 'NOW()' => SQL NOW()
     *
     * @param string|\Som_Model_Abstract $table_name Table name
     * @param array|bool $data Associative or 2D array containing data for update
     * @param string $condition Body of SQL WHERE clause
     * @param array $parameters Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
     * @param bool $update_null Nullify cells which have null values in the array. By default they are skipped
     * @return int The number of affected records or FALSE on error
     * @throws Exception
     */
    public function update($table_name, $data = false, $condition = '', $parameters = array(), $update_null = false) {
        $model = null;
        $tq = $this->tableQuote;

        if ($table_name instanceof Som_Model_Abstract) {
            $model = $table_name;
            $table_name = $this->_dbinfo['tbname'];
            $pkey = $this->_dbinfo['pkey'];

            if (empty($data)) {
                // When save models, by default we must save null values too
                if(empty($condition) && empty($parameters)) $update_null = true;
                $data = $model->toArray();
            }
            $id = $data[$pkey];
            unset($data[$data[$pkey]]);
            $condition = "{$pkey}={$id}";
        }

        if (!is_array($data)) return 0;

        // Сохранить связи
        if (!empty($model)) {
            $fields = $model::fields();
            if (!empty($fields)) {
                foreach ($fields as $name => $field) {
                    if (isset($field['link']) && in_array($field['link']['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                        $fieldData = $model->rawValue($name);
                        if (empty($fieldData)) $fieldData = null;
                        $this->saveXRef($field['link']["model"], $id, $fieldData, $name);
                    }
                }
            }
        }

        $upd = '';
        if (!is_array($parameters)) $parameters = array($parameters);

        if(!is_object($table_name) && is_array($condition) && !empty($condition)){
            $class = $this->_dbinfo['class'];
            $class = new $class();
            list($condition, $parameters) = $this->parseConditions($condition);
            unset($class);
        } else {
            $condition = empty($condition) ? '' : 'WHERE ' . $condition;
        }

        foreach ($data as $key => $val) {
            if (is_null($val) && !$update_null) continue;

            $upd .= "{$tq}$key{$tq}=";
            if (is_null($val)) {
                $upd .= 'NULL,';
            } elseif ($val === 'NOW()') {
                $upd .= 'NOW(),';
            } elseif (is_int($val) || is_float($val)) {
                $upd .= $val . ',';
            } else {
                $upd .= $this->quote($val) . ',';
            }
        }

        if (!empty($upd)) {
            $upd = mb_substr($upd, 0, -1);
            $query = "UPDATE {$tq}$table_name{$tq} SET $upd $condition";
            if (count($parameters) > 0) {
                $stmt = $this->_adapter->prepare($query);

                $this->_bindParams($stmt, $parameters);

                if ($stmt->execute() === false) {
                    $array = $this->_adapter->errorInfo();
                    throw new Exception('SQL Error: ' . $array[2]);
                }

            } else {
                $res = $this->_adapter->exec($query);
                if ($res === false) {
                    $array = $this->_adapter->errorInfo();
                    throw new Exception('SQL Error: ' . $array[2]);
                }
                if (empty($res))  return 0;
            }

            return $res;
        }
        return 0;
    }

    /**
     * Performs simple SQL DELETE query and returns number of removed items.
     *
     * @param string $table_name |\Som_Model_Abstract Table name
     * @param string $condition Body of WHERE clause
     * @param array $parameters Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
     * @return int Number of records removed on success or FALSE on error
     */
    public function delete($table_name, $condition = '', $parameters = array()) {
        $model = null;
        $tq = $this->tableQuote;

        if ($table_name instanceof Som_Model_Abstract) {
            $model = $table_name;
            $table_name = $this->_dbinfo['tbname'];
            $pkey = $this->_dbinfo['pkey'];

            if (empty($data)) $data = $model->toArray();
            $id = $data[$pkey];
            $condition = "{$pkey}={$id}";

            $fields = $model->fieldList();
            if (!empty($fields)) {
                foreach ($fields as $name => $field) {
                    if ($field['type'] == 'link' && in_array($field['link']['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
                        $this->deleteXRef($field['link']["model"], $id, $name);
                    }
                }
            }
        }

        $query = empty($condition) ? "DELETE FROM {$tq}$table_name{$tq}" : "DELETE FROM {$tq}$table_name{$tq} WHERE $condition";
        if (!is_array($parameters)) $parameters = array($parameters);

        if (count($parameters) > 0) {
            $stmt = $this->_adapter->prepare($query);
            $this->_bindParams($stmt, $parameters);
            if ($stmt->execute() === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }
            $res = $stmt->rowCount();
        } else {
            $res = $this->_adapter->exec($query);
            if ($res === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }
        }
        return $res;
    }

    /**
     * Increment
     * @param $tablename
     * @param array $pair
     * @param string $conditions
     * @return bool
     */
    public function inc($tablename, $pair, $conditions = '') {
        return $this->incdec($tablename, $pair, $conditions, true);
    }

    /**
     * Decrement
     * @param $tablename
     * @param array $pair
     * @param string $conditions
     * @return bool
     */
    public function dec($tablename, $pair, $conditions = '') {
        return $this->incdec($tablename, $pair, $conditions, false);
    }

    /**
     * @param $tablename
     * @param array $pairs
     * @param string $conditions
     * @param bool $inc
     * @return bool
     * @throws Exception
     */
    protected function incdec($tablename, $pairs, $conditions = '', $inc = true) {
        if (!empty($pairs)) {
            $conditions = empty($conditions) ? '' : 'WHERE ' . $conditions;
            $pairupd   = array();
            foreach ($pairs as $field => $val) {
                $pairupd [] = " $field = $field " . ($inc ? '+ ' : '- ') . $val;
            }
            $upd   = implode(',', $pairupd);
            $query = "UPDATE ".static::quoteIdentifier($tablename)." SET $upd $conditions";
            $res   = $this->_adapter->exec($query);
            if ($res === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }

            return true;
        }
        return false;
    }

    /**
     * Создать поле
     * @param array $field
     * @param string $table
     * @return bool
     */
    public function createField($field, $table = '')
    {

        $tq = $this->tableQuote;
        if (empty($table)) $table = $this->_dbinfo['tbname'];

        if (!is_array($field)) return false;
        if (empty($field['name'])) return false;

        $field['name'] = mb_strtolower($field['name']);

        if ($this->fieldExists($field['name'], $table)) return false;

        $defenition = '';
        if (empty($field['type'])) return false;

        if ($field['type'] == 'link') {
            if (in_array($field['link']['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL))) {
                $field['type'] = 'bigint';
            } else {
                return false;
            }
        }

        $defenition .= $field['type'];

        if (mb_strtolower($field['type']) == 'varchar') {
            if (empty($field['length']))
                $field['length'] = 255;
            $defenition .= "({$field['length']})";
        }

        if (isset($field['nullable'])) {
            if (!$field['nullable']) $defenition .= " NOT NULL";
        }

        if (isset($field['default'])) {
            if ($field['default'] === NULL) {
                // хз пока, все зависит от типа
                $defenition .= " DEFAULT NULL";
            } elseif (!empty($field['default'])) {
                $defenition .= " DEFAULT " . $this->_adapter->quote($field['default']);
            }
        }
        $sql = "ALTER TABLE {$tq}{$table}{$tq} ADD COLUMN {$field['name']} $defenition";
        $res = $this->_adapter->query($sql);
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }

        return $res;
    }

    /**
     * Parses query conditions from string or array
     *
     * @param mixed $conditions SQL WHERE conditions as string or numeric array of strings or array of arrays
     * @param array $params Optional PDO params to pass through
     * @throws Exception
     * @return array SQL WHERE part and PDO params
     * @todo описание условий
     */
    public function parseConditions($conditions = array(), $params = array()) {
        $joins    = array();

        if (empty($conditions)) return array('', array(), array());

        $where = $this->parseCondition($conditions, $params, $joins);

        if(!empty($where)) $where = 'WHERE '.$where;

        return array($where, $params, $joins);
    }

    /**
     * @param array $conditions
     * @param array $params
     * @param int   $i              counter
     * @return null|string
     * @throws Exception
     */
    protected function parseCondition(&$conditions, &$params, &$joins, $i = 0) {
        /** @var Som_Model_Abstract $class */
        $class = $this->_dbinfo['class'];
        $table = $this->_dbinfo['tbname'];
        if(empty($table) && !empty($class))  $table = $class::tableName();
        $tq = $this->tableQuote;

        $where = '';
        $orWhere = '';
        $joins   = array();

        if (!is_array($conditions)) $conditions = array($conditions);
        if (count($conditions) > 0) {
            $where = array();
            $orWhere = array();

            foreach ($conditions as $condition) {
                $i++;
                if (is_array($condition)) {
                    // todo проверка существования колонки $cond[0]
                    if (!isset($condition[1])) $condition[1] = NULL;
                    if (empty($condition[2])) $condition[2] = '=';
                    if (empty($condition[3]) || $condition[3] != 'OR') $condition[3] = 'AND';

                    $column = $condition[0];
                    $value = $condition[1];
                    $operand = $condition[2];

                    /**
                     * Первый параметр  - массив. может содержать вложенные условия.
                     * Например:
                     * $condition[]=[
                     *    [['desc', '*' . $kw . '*'],['text', '*' . $kw . '*', null, "OR"]]
                     * ];
                     */
                    if (isset($condition[0]) && is_array($condition[0])) {
                        $wh = $this->parseCondition($condition[0], $params, $joins, $i);

                    } else {
                        $tblCol = (!empty($table)) ? static::quoteIdentifier($table) . '.' . static::quoteIdentifier($column) :
                            static::quoteIdentifier($column);

                        if (mb_strpos($column, '.') !== false) {
                            $tmp = explode('.', $column);
                            $tmp[0] = ($tmp[0] != '') ? $tmp[0] : $table;
                            $tblCol = (!empty($tmp[0])) ? static::quoteIdentifier($tmp[0]) . '.' . static::quoteIdentifier($tmp[1]) :
                                static::quoteIdentifier($tmp[1]);
                            $column = $tmp[1];
                        }


                        // Если передали объект
                        if ($condition[0] instanceof Som_Model_Abstract) {
                            $fields = $class::fieldList();
                            // todo дописать обработку полей
                            if (empty($condition[1])) {
                                // Не передали поле, ищем первое попавшиеся
                                $fld = $class::field($condition[0]);
                                // todo exception
                                if (empty($fld)) throw new Exception("В моделе '{$class}' нет связи с '" . get_class($condition[0]) . "'");
                            } else {
                                $fld = $class::field($condition[1]);
                                if (empty($fld)) {
                                    throw new Exception("В моделе '{$class}' поле '{$condition[1]}' не найдено");
                                }
                                if (!is_a($condition[0], $fld['model'])) {
                                    throw new Exception("В условии выборки переданный объект класса '" . get_class($condition[0]) . "' не
                                соответствует модели '{$fld['model']}' связанной c полем '{$condition[1]}'");
                                }
                            }
                            $value = (int)$condition[0]->getId();
                            $column = $fld['name'];
                        }

                        if ($column == 'RAW' || $column == 'SQL') {
                            $wh = $value ? $value : '';
                        } elseif (is_array($value)) {
                            $wh = ($value ? ($tblCol . ($operand == '<>' ? ' NOT' : '') . ' IN (' . implode(',', self::quote($value)) . ')') : '0');
                        } elseif ($value === null) {
                            $wh = ("{$tblCol} IS " . ($operand == '<>' ? 'NOT ' : '') . 'NULL');
                        } else {
                            if (strpos($value, '*') !== false) {
                                $wh = "$tblCol LIKE :$column" . $i;
                                $params[$column . $i] = str_replace('*', '%', $value);
                            } else {
                                $wh = $tblCol . ' ' . $operand . ' :' . $column . $i;
                                $params[$column . $i] = $value;
                            }
                        }
                    }

                    if ($condition[3] != 'OR') {
                        $where[] = $wh;
                    } else {
                        $orWhere[] = $wh;
                    }

                } else {
                    // Парсим строковые условия
                    $parts = array();
                    // TODO support more SQL operators
                    preg_match_all('/(.+?)([<>= ]+)(.+)/', $condition, $parts);
                    $column = trim($parts[1][0]);
                    $operator = trim($parts[2][0]);
                    $value = trim(trim($parts[3][0]), '\'"`');
                    if ($column && $operator) {
                        $sql = (!empty($table)) ? static::quoteIdentifier($table).'.'.static::quoteIdentifier($column) :
                            static::quoteIdentifier($column);

                        $where[] = "$sql $operator :$column";

                        if ((intval($value) == $value) && (strval(intval($value)) == $value)) $value = intval($value);
                        $params[$column] = $value;
                    } else {
                        // Поддержка прямых условий НЕ Безопасно!!!
                        $where[] = "$condition";

                    }
                }
            }

            if (!empty($where)) {
                $where = '(' . implode(') AND (', $where) . ')';

            } elseif (count($orWhere) > 0) {
                $where = '(' . $orWhere[0] . ')';
                unset ($orWhere[0]);
            }
            if (count($orWhere) > 0) $where .= ' OR (' . implode(') OR (', $orWhere) . ")";
        }
        return $where;
    }

    /**
     * Parse Order clause
     * @param string|array $order
     * @throws Exception
     * @return string
     */
    public function parseOrder($order)
    {

        // Если передали строку
        if (!is_array($order)) {
            return $order;
        }

        if (is_array($order)) {
            $ord = array();
            foreach ($order as $cond) {
                if (is_string($cond)) {
                    $ord[] = $cond;
                } elseif (is_array($cond)) {
                    if (empty($cond[1])) {
                        $cond[1] = 'ASC';
                    } else {
                        $cond[1] = trim(strtoupper($cond[1]));
                        if (!in_array($cond[1], array('ASC', 'DESC'))) {
                            throw new Exception("Wrong order direct '{$cond[1]}'. Must be 'ASC', 'DESC' or empty ");
                        }
                    }
                    // todo проверка существования колонки $cond[0]
                    $ord[] = $this->tableQuote . $cond[0] .$this->tableQuote . ' ' . $cond[1];
                }

            }
            if (count($ord) > 0) return implode(', ', $ord);
            return '';
        }

    }

    /**
     * Экранирование данных для запроса
     * @static
     * @param mixed $data строка или массив строк для экранирования
     * @return array|string
     */
    public function quote($data)
    {
        if (is_string($data)) return $this->_adapter->quote($data);

        if (!is_array($data)) return $data;

        foreach ($data as $key => $str) {
            if (!(strval(intval($data[$key])) == $data[$key])) $data[$key] = $this->_adapter->quote($str);
        }

        return $data;
    }

    /**
     * Returns the symbol the adapter uses for delimited identifiers.
     *
     * @return string
     */
    public function getQuoteIdentifierSymbol()
    {
        return $this->tableQuote;
    }

    /**
     * Quotes an identifier.
     *
     * @param string $ident The identifier.
     * @return string The quoted identifier.
     */
    public function quoteIdentifier($ident)
    {
        $q = $this->getQuoteIdentifierSymbol();
        return ($q . str_replace("$q", "$q$q", $ident) . $q);
    }

    /**
     * Binds parameters to a statement
     *
     * @param PDOStatement $statement PDO statement
     * @param array $parameters Array of parameters, numeric or associative
     * @throws Exception
     */
    private function _bindParams($statement, $parameters)
    {
        $is_numeric = is_int(key($parameters));
        foreach ($parameters as $key => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $is_numeric ? $statement->bindValue($key + 1, $val, $type) : $statement->bindValue($key, $val, $type);
            if ($statement === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }
        }
    }

    /**
     * Загрузить связи
     * @param string|Som_Model_Abstract $xModel
     * @param $id
     * @param string $fieldName
     * @return array|bool|null
     */
    public function loadXRef($xModel, $id, $fieldName = '')
    {
        global $db_x;

        $tq = $this->tableQuote;

        if (is_string($xModel) || ($xModel instanceof Som_Model_Abstract)) {
            /** @var Som_Model_Abstract $xModel */
            $xPk = $xModel::primaryKey();
            $linkModelDbInfo = $xModel::getDbConfig();

            $xTableName = $linkModelDbInfo['tbname'];
        }else{
            return false;
        }

        $tableName = $this->_dbinfo['tbname'];

        // Убираем префиксы имен таблиц
        $tableName  = preg_replace("/^$db_x/iu", '', $tableName);
        $xTableName = preg_replace("/^$db_x/iu", '', $xTableName);

        $pk = $this->_dbinfo['pkey'];
        $pKey  = mb_strtolower($tableName . '_' . $pk);
        $xPKey = mb_strtolower($xTableName . '_' . $xPk);

        $xRefTableName1 = $db_x.mb_strtolower("{$tableName}_link_{$xTableName}");
        $xRefTableName2 = $db_x.mb_strtolower("{$xTableName}_link_{$tableName}");

        $xRefTable = false;
        if ($this->tableExists($xRefTableName1)) {
            $xRefTable = $xRefTableName1;
        } elseif ($this->tableExists($xRefTableName2)) {
            $xRefTable = $xRefTableName2;
        }
        if (!$xRefTable) return false;

        $query = "SELECT ".$this->quoteIdentifier($xPKey)." FROM ".$this->quoteIdentifier($xRefTable).
            " WHERE ".$this->quoteIdentifier($pKey)."={$id} AND ".$this->quoteIdentifier('name')."=".$this->quote($fieldName);

        $xRefs = $this->query($query)->fetchAll(PDO::FETCH_COLUMN);

        if (count($xRefs) <= 0) return null;

        return $xRefs;
    }

    /**
     * Сохранить связи
     * @param string|Som_Model_Abstract $xModel
     * @param $id
     * @param $data
     * @param string $fieldName
     * @return bool
     * @throws Exception
     */
    protected function saveXRef($xModel, $id, $data, $fieldName = '')
    {
        if (is_string($xModel) || ($xModel instanceof Som_Model_Abstract)) {
            /** @var Som_Model_Abstract $xModel */
            $xPk = $xModel::primaryKey();
            $linkModelDbInfo = $xModel::getDbConfig();

            $xTableName = $linkModelDbInfo['tbname'];
        }else{
            return false;
        }

        $tableName = $this->_dbinfo['tbname'];
        $pk = $this->_dbinfo['pkey'];

        return $this->saveRelations($tableName, $xTableName, $id, $data, $fieldName, $pk, $xPk);
    }

    /**
     * Сохранить связи между таблицами
     *
     * @param $table
     * @param $rTable
     * @param $pkey
     * @param $rPkey
     * @param $id
     * @param $data
     * @param $fieldName
     * @return bool
     * @throws Exception
     */
    public function saveRelations($table, $rTable, $id, $data, $fieldName = '',  $pkey = 'id', $rPkey = 'id') {
        global $db_x;
        $tq = $this->tableQuote;

        // Убираем префиксы имен таблиц
        $table  = preg_replace("/^$db_x/iu", '', $table);
        $rTable = preg_replace("/^$db_x/iu", '', $rTable);

        $priKey  = mb_strtolower($table . '_' . $pkey);
        $rPriKey = mb_strtolower($rTable . '_' . $rPkey);

        $xRefTableName1 = $db_x.mb_strtolower("{$table}_link_{$rTable}");
        $xRefTableName2 = $db_x.mb_strtolower("{$rTable}_link_{$table}");

        $xRefTable = false;
        if ($this->tableExists($xRefTableName1)) {
            $xRefTable = $xRefTableName1;
        } elseif ($this->tableExists($xRefTableName2)) {
            $xRefTable = $xRefTableName2;
        } else {
            // Создать таблицу
            $xRefTable = $xRefTableName1;
            $this->createTable($xRefTable, array(
                'xref_id' =>
                    array(
                        'name' => 'xref_id',
                        'type' => 'int',
                        'primary' => true,
                    ),
                $priKey =>
                    array(
                        'name' => $priKey,
                        'type' => 'int',
                    ),
                $rPriKey =>
                    array(
                        'name' => $rPriKey,
                        'type' => 'int',
                    ),
                'name' =>
                    array(
                        'name' => 'name',
                        'type' => 'varchar',
                    )
            ));
        }

        // Сохраняем связи
        $query = "SELECT ".$this->quoteIdentifier($rPriKey)." FROM $xRefTable WHERE ".$this->quoteIdentifier($priKey).
            "={$id} AND ".$this->quoteIdentifier('name')."=".$this->quote($fieldName);

        $old_xRefs = $this->query($query)->fetchAll(PDO::FETCH_COLUMN);

        if (!$old_xRefs) $old_xRefs = array();
        $kept_xRefs = array();
        $new_xRefs = array();

        // Find new links, count old links that have been left
        $cnt = 0;
        $isStr = false;

        if (!empty($data)) {
            foreach ($data as $item) {
                $p = array_search($item, $old_xRefs);
                if ($p !== false) {
                    $kept_xRefs[] = $old_xRefs[$p];
                    $cnt++;
                } else {
                    $new_xRefs[] = $item;
                }
            }
        }

        // Remove old links that have been removed
        $rem_xRefs = array_diff($old_xRefs, $kept_xRefs);
        if (count($rem_xRefs) > 0) {
            $inCond = "(" . implode(",", $this->quote($rem_xRefs)) . ")";
            $this->delete($xRefTable, "{$priKey}=$id AND {$rPriKey} IN $inCond AND ".$this->quoteIdentifier('name')."=".$this->quote($fieldName));
        }

        // Add new xRefs
        foreach ($new_xRefs as $item) {
            $isStr = !is_int($item) && !is_float($item) && is_string($item);

            if ((!$isStr && $item > 0) || ($isStr && $item != '')) {
                $upData = array(
                    $priKey => $id,
                    $rPriKey => $item,
                    'name' => $fieldName
                );
                $res = $this->insert($xRefTable, $upData);

                if ($res === false) {
                    $error = $this->_adapter->errorInfo();
                    throw new Exception("SQL Error {$error[0]}: {$error[2]}");
                };
            }
        }

        return true;
    }

    /**
     * Удалить связи между моделями
     *
     * @param string|Som_Model_Abstract $xModel
     * @param $id
     * @param string $fieldName
     * @return bool
     */
    protected function deleteXRef($xModel, $id, $fieldName = '')
    {
        if (is_string($xModel) || ($xModel instanceof Som_Model_Abstract)) {
            /** @var Som_Model_Abstract $xModel */
            $xPk        = $xModel::primaryKey();
            $xTableName = $xModel::tableName();
        }else{
            return false;
        }
        $tableName  = $this->_dbinfo['tbname'];
        $pk         = $this->_dbinfo['pkey'];

        $this->deleteRelations($tableName, $xTableName, $id, $fieldName, $pk, $xPk);
    }

    /**
     * Удалить связи между таблицами
     *
     * @param string $table
     * @param string $rTable        Связь с этой таблицей будет удалена
     * @param int    $id            Значение первичного ключа из $table
     * @param string $fieldName
     * @param string $pkey
     * @param string $rPkey
     * @throws Exception
     */
    public function deleteRelations($table, $rTable, $id, $fieldName = '',  $pkey = 'id', $rPkey = 'id')
    {
        global $db_x;

        // Убираем префиксы имен таблиц
        $table = preg_replace("/^$db_x/iu", '', $table);
        $rTable = preg_replace("/^$db_x/iu", '', $rTable);

        $priKey = mb_strtolower($table . '_' . $pkey);
        $rPriKey = mb_strtolower($rTable . '_' . $rPkey);

        $xRefTableName1 = $db_x . mb_strtolower("{$table}_link_{$rTable}");
        $xRefTableName2 = $db_x . mb_strtolower("{$rTable}_link_{$table}");

        if ($this->tableExists($xRefTableName1)) {
            $this->delete($xRefTableName1, "{$priKey}=$id AND ".$this->quoteIdentifier('name')."=".$this->quote($fieldName));
        }
        if ($this->tableExists($xRefTableName2)) {
            $this->delete($xRefTableName2, "{$priKey}=$id AND ".$this->quoteIdentifier('name')."=".$this->quote($fieldName));
        }
    }

    /**
     * Parses PDO exception message and returns its components and status
     *
     * @param PDOException $e PDO Exception
     * @param string $err_code Output error code parameter
     * @param string $err_message Output error message parameter
     * @return bool TRUE for error cases, FALSE for notifications and warnings
     */
    private function _parseError(PDOException $e, &$err_code, &$err_message)
    {
        $pdo_message = $e->getMessage();
        if (preg_match('#SQLSTATE\[(\w+)\].*?: (.*)#', $pdo_message, $matches)) {
            $err_code = $matches[1];
            $err_message = $matches[2];
        } else {
            $err_code = $e->getCode();
            $err_message = $pdo_message;
        }
        return $err_code > '02';
    }


    public function getDbInfo()
    {
        return $this->_dbinfo;
    }

}
