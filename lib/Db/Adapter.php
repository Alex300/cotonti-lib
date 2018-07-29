<?php

namespace lib\Db;

defined('COT_CODE') or die('Wrong URL.');

use lib\Db;
use lib\Db\Query\Builder;
use lib\Helpers\Inflector;

/**
 * Abstract DB Adapter class
 *
 * @todo transaction support
 * @todo parse conditions, parse orders and ect move to builder class
 */
abstract class Adapter
{
    /**
     * @var \PDO[] connections registry
     */
    protected static $connections = array();

    protected $dataBaseType = '';

    /**
     * @var string Символ quoted identifier
     * @deprecated
     */
    //protected $tableQuote = '';

    /**
     * Character used to quote schema, table, etc. names.
     * An array of 2 characters can be used in case starting and ending characters are different.
     *
     * @var string|string[]
     */
    protected $tableQuoteCharacter = "'";

    /**
     * Character used to quote column names.
     * An array of 2 characters can be used in case starting and ending characters are different.
     *
     * @var string|string[]
     */
    protected $columnQuoteCharacter = '"';


    protected $tablePrefix = '';

    /**
     * @var Builder
     */
    protected $builder = null;

    /**
     * @var array
     * @deprecated
     */
    //protected $_dbinfo = null;

    /**
     * Number of rows affected by the most recent query
     * @var int
     */
    protected $_affected_rows = 0;

    /**
     * @var \PDO ссылка на объект БД
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
     * @param string $dbc
     * @throws \Exception
     */
    function __construct($dbc = 'db')
    {
        $this->_adapter = static::connect($dbc);
        $settings = Db::connectionSettings($dbc);

        if(isset($settings['prefix'])) $this->tablePrefix = $settings['prefix'];
    }

    /**
     * Connect to Data Base
     * @param $dbc
     * @return \PDO
     * @throws \Exception
     */
    protected static function connect($dbc)
    {
        if (empty(self::$connections[$dbc])) {

            // Connect to DB
            $cfg = Db::connectionSettings($dbc);

            $dbc_port = empty($cfg['port']) ? '' : ';port=' . $cfg['port'];

            $dsn = $cfg['adapter'] . ':host=' . $cfg['host'] . $dbc_port .';dbname=' . $cfg['dbname'];

            self::$connections[$dbc] = new \PDO($dsn, $cfg['username'], $cfg['password']);
        }

        return self::$connections[$dbc];
    }

    /**
     * Close DB connection
     * @param $dbc
     */
    public static function closeConnection($dbc){
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


    public function getBuilder()
    {
        if(empty($this->builder)) {
            $className = '\lib\Db\\' . $this->dataBaseType . '\Builder';
            if (class_exists($className)) {
                $this->builder = new $className($this);

            } else {
                // Fallback
                $this->builder = new \lib\Db\Query\Builder($this);
            }
        }
        return $this->builder;
    }

    /**
     * Get the connection's table prefix.
     *
     * @return string
     */
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }

    /**
     * Set the connection's table prefix.
     *
     * @param  string  $prefix
     * @return $this
     */
    public function setTablePrefix($prefix)
    {
        $this->tablePrefix = $prefix;

        return $this;
    }

    public function getDataBaseType()
    {
        return $this->dataBaseType;
    }

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
     * @return \PDOStatement
     * @throws \Exception
     */
    public function query($query, $parameters = [])
    {

        if (!is_array($parameters)) $parameters = array($parameters);

        if (count($parameters) > 0) {
            $result = $this->_adapter->prepare($query);
            $this->_bindParams($result, $parameters);

            if ($result->execute() === false) {
                $array = $this->_adapter->errorInfo();
                throw new \Exception('SQL Error: ' . $array[2]);
            }

        } else {
            $result = $this->_adapter->query($query);
            if ($result === false) {
                $array = $this->_adapter->errorInfo();
                throw new \Exception('SQL Error: ' . $array[2]);
            }
        }

        // We use PDO::FETCH_ASSOC by default to save memory
        $result->setFetchMode(\PDO::FETCH_ASSOC);
        $this->_affected_rows = $result->rowCount();
        return $result;
    }


    /**
     * Get a list of active record models that match the specified condition
     *
     * @param string|ActiveRecord $tableName table name or ActiveRecord model class name or Active record instance
     * @param array $conditions
     * @param int   $limit
     * @param int   $offset
     * @param array|string $order
     * @param array $columns
     *
     * @return bool|ActiveRecord[]
     * @throws \ReflectionException
     */
    public function fetch($tableName = '', $conditions = [], $limit = 0, $offset = 0, $order = '', $columns = [])
    {
        $hasModel = false;

        /** @var ActiveRecord $modelClass */
        $modelClass = null;
        if ($tableName instanceof ActiveRecord) {
            $modelClass = get_class($tableName);
            $hasModel = true;

        } elseif (is_string($tableName)) {
            if(class_exists($tableName)) {
                $classReflection = new \ReflectionClass($tableName);
                $hasModel = $classReflection->isSubclassOf(ActiveRecord::class);
                if ($hasModel) {
                    $modelClass = $tableName;
                }
            }
        }

        if(!empty($modelClass)) $tableName = $modelClass::tableName();

        $joins = [];
        $addColumns = [];

        $calssCols = !empty($columns) ? $columns : ['*'];

//        if (empty($columns)) {
//            if(!empty())
//            $calssCols = $modelClass::columns();
//
//        } else {
//            $calssCols = $columns;
//        }

        if (!empty($modelClass::$fetchJoins))   $addJoins   = $modelClass::$fetchJoins;
        if (!empty($modelClass::$fetchColumns)) $addColumns = $modelClass::$fetchColumns;

        $columns = [];
        foreach ($calssCols as $col) {
            $columns[] = $this->quoteTableName($tableName).'.'.$this->quoteColumnName($col);
        }
        if (!empty($addColumns)) $columns = array_merge($columns, $addColumns);
        $columns = implode(', ', $columns);

        list($where, $params, $joins) = $this->parseConditions($tableName, $conditions);
        if (!empty($addJoins)) $joins = array_merge($joins, $addJoins);
        $joins = implode("\n", $joins);

        //$this->getBuilder()

        if (!empty($order)) $order = $this->parseOrder($order);
        $where = ($where) ? "\n WHERE {$where}" : '';
        $order = ($order) ? "\n ORDER BY $order" : '';
        $limit = ($limit) ? "\n LIMIT $limit OFFSET $offset" : '';
        $joins = ($joins) ? "\n $joins" : '';

        $sql = "SELECT $columns\n FROM ".$this->quoteTableName($tableName).$joins.$where.$order.$limit;

        $res = $this->query($sql, $params);
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new \Exception('SQL Error: ' . $array[2]);
        }

        $result = [];
        while ($row = $res->fetch(\PDO::FETCH_ASSOC)) {
            if(!empty($modelClass)) {
                $obj = new $modelClass($row);
                $result[$row[$modelClass::primaryKey()]] = $obj;

            } else {
                $result[] = $row;
            }
        }

        if ($res->closeCursor() === false) {
            $array = $this->_adapter->errorInfo();
            throw new \Exception('SQL Error: ' . $array[2]);
        }

        return (count($result) > 0) ? $result : null;
    }

    /**
     * Get the number of records that match the specified condition
     *
     * @param string $table
     * @param $conditions
     *
     * @throws \Exception
     * @return int
     */
    public final function getCount($table, $conditions)
    {
        $modelClass = null;

        if (empty($table)) {
            if($table instanceof ActiveRecord) {
                $modelClass = $table;
                $table = $modelClass::tableName();

                if (!empty($modelClass::$fetchJoins)) $addJoins = $modelClass::$fetchJoins;
            }
        }

        list($where, $params, $joins) = $this->parseConditions($conditions);
        if (!empty($addJoins)) $joins = array_merge($joins, $addJoins);
        $joins = implode("\n", $joins);

        $sql = "SELECT COUNT(*) FROM ".$this->quoteColumnName($table)."\n $joins\n $where\n";
        $res = $this->query($sql, $params)->fetchColumn();
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new \Exception('SQL Error: ' . $array[2]);
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
     * @param string|array $tableName Table name or array [tableName, primaryKey]
     *       last needed to execute lastInsertId in PostgreSql with generated sequence name
     * @param array|bool $data Associative or 2D array containing data for insertion.
     * @param bool $insert_null Insert SQL NULL for empty values rather than ignoring them.
     * @param bool $ignore Ignore duplicate key errors on insert
     * @param array $update_fields List of fields to be updated with ON DUPLICATE KEY UPDATE
     * @return int The number of affected records
     * @throws \Exception
     */
    public function insert($tableName, $data = false, $insert_null = false, $ignore = false, $update_fields = array()) {
        //$modelClass = null;
        $primaryKey = '';
//        if ($tableName instanceof ActiveRecord) {
//            $modelClass = $tableName;
//            $tableName = $modelClass::tableName();
//            $pkey = $modelClass::primaryKey();
//
//            if (empty($data)) $data = $modelClass->toRawArray();
//        }

        if (!is_array($data)) return 0;

        if(is_array($tableName)) {
            $primaryKey = $tableName[1];
            $tableName = $tableName[0];
        }

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
                        $keys .= $this->quoteColumnName($key);
                    }
                    if (is_null($val) || $val === 'NULL') {
                        $vals .= 'NULL';
                        
                    } elseif (is_bool($val)) {
                        $vals .= $val ? 'TRUE' : 'FALSE';
                    
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
            $query = "INSERT $ignore INTO {$this->quoteTableName($tableName)} ($keys) VALUES $vals";
            if (count($update_fields) > 0) {
                $query .= ' ON DUPLICATE KEY UPDATE';
                $j = 0;
                foreach ($update_fields as $key) {
                    if ($j > 0) $query .= ',';
                    $query .= ' '.$this->quoteColumnName($key).' = VALUES('.$this->quoteColumnName($key).')';
                    $j++;
                }
            }
//            $this->_startTimer();
            /**
             * @var \PDOStatement $res ;
             */
            $res = $this->_adapter->query($query);
            if ($res === false) {
                $array = $this->_adapter->errorInfo();
                throw new \Exception('SQL Error: ' . $array[2]);
            }
//            $this->_stopTimer($query);

            $id = $this->lastInsertId($tableName, $primaryKey);

//            if ($id > 0 && !empty($model)) {
//                $data[$pkey] = $id;
//                $fields = $model::fields();
//                $model->{$pkey} = $id;
//                if (!empty($fields)) {
//                    foreach ($fields as $fieldName => $field) {
//                        if (isset($field['link']) && in_array($field['link']['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL))) {
//                            $fieldData = $model->rawValue($fieldName);
//                            if (empty($fieldData)) $fieldData = null;
//                            $this->saveXRef($field['link']["model"], $data[$pkey], $fieldData, $fieldName);
//                        }
//                    }
//                }
//            }

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
     * @param string $tableName Table name
     * @param array|bool $data Associative or 2D array containing data for update
     * @param array|string $condition Body of SQL WHERE clause
     * @param array $parameters Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
     * @param bool $update_null Nullify cells which have null values in the array. By default they are skipped
     * @return int The number of affected records or FALSE on error
     * @throws \Exception
     */
    public function update($tableName, $data = false, $condition = '', $parameters = array(), $update_null = false) {
//        $model = null;
//        $tq = $this->tableQuote;
//
//        if ($table_name instanceof ActiveRecord) {
//            $model = $table_name;
//            $table_name = $model::tableName();
//            $pkey = $model::primaryKey();
//
//            if (empty($data)) {
//                // When save models, by default we must save null values too
//                if(empty($condition) && empty($parameters)) $update_null = true;
//                $data = $model->toArray();
//            }
//            $id = $data[$pkey];
//            unset($data[$data[$pkey]]);
//            $condition = "{$pkey}={$id}";
//        }

        if (!is_array($data)) return 0;

        // Сохранить связи
//        if (!empty($model)) {
//            $fields = $model::fields();
//            if (!empty($fields)) {
//                foreach ($fields as $name => $field) {
//                    if (isset($field['link']) && in_array($field['link']['relation'], array(\Som::TO_MANY, \Som::TO_MANY_NULL))) {
//                        $fieldData = $model->rawValue($name);
//                        if (empty($fieldData)) $fieldData = null;
//                        $this->saveXRef($field['link']["model"], $id, $fieldData, $name);
//                    }
//                }
//            }
//        }

        $upd = '';
        if (!is_array($parameters)) $parameters = array($parameters);

        if(is_array($condition) && !empty($condition)){
            list($condition, $condParameters) = $this->parseConditions($tableName, $condition);
            $parameters = array_merge($condParameters);

        }

        $condition = empty($condition) ? '' : 'WHERE ' . $condition;

        foreach ($data as $key => $val) {
            if (is_null($val) && !$update_null) continue;

            $upd .= $this->quoteColumnName($key).'=';
            if (is_null($val) || $val === 'NULL') {
                $upd .= 'NULL,';
                
            } elseif (is_bool($val)) {
                $upd .= $val ? 'TRUE,' : 'FALSE,';
                
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
            $query = "UPDATE {$this->quoteTableName($tableName)} SET $upd $condition";
            if (count($parameters) > 0) {
                $stmt = $this->_adapter->prepare($query);

                $this->_bindParams($stmt, $parameters);

                if ($stmt->execute() === false) {
                    $array = $this->_adapter->errorInfo();
                    throw new \Exception('SQL Error: ' . $array[2]);
                }

            } else {
                $res = $this->_adapter->exec($query);
                if ($res === false) {
                    $array = $this->_adapter->errorInfo();
                    throw new \Exception('SQL Error: ' . $array[2]);
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
     * @param string $tableName Table name
     * @param string $condition Body of WHERE clause
     * @param array $parameters Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
     * @return int Number of records removed on success or FALSE on error
     * @throws \Exception
     */
    public function delete($tableName, $condition = '', $parameters = array())
    {
//        $model = null;
//
//        if ($table_name instanceof ActiveRecord) {
//            $model = $table_name;
//            $table_name = $model::tableName();
//            $pkey = $model::primaryKey();
//
//            $id = $model->getId();
//            $condition = "{$pkey}={$id}";
//
//            $fields = $model::fields();
//            if (!empty($fields)) {
//                // Remove all data from relations junction tables
//                // Todo move to active record
//                foreach ($fields as $name => $field) {
//                    if ($field['type'] == 'link' && in_array($field['link']['relation'], array(\Som::TO_MANY, \Som::TO_MANY_NULL))) {
//                        $this->deleteXRef($field['link']["model"], $id, $name);
//                    }
//                }
//            }
//        }

        if(is_array($condition) && !empty($condition)){
            list($condition, $condParameters) = $this->parseConditions($tableName, $condition);
            $parameters = array_merge($condParameters);
        }

        if(!empty($condition)) $condition = ' WHERE '.$condition;

        $query = 'DELETE FROM '.$this->quoteTableName($tableName).$condition;
        if (!is_array($parameters)) $parameters = [$parameters];

        if (count($parameters) > 0) {
            $stmt = $this->_adapter->prepare($query);
            $this->_bindParams($stmt, $parameters);
            if ($stmt->execute() === false) {
                $array = $this->_adapter->errorInfo();
                throw new \Exception('SQL Error: ' . $array[2]);
            }
            $res = $stmt->rowCount();

        } else {
            $res = $this->_adapter->exec($query);
            if ($res === false) {
                $array = $this->_adapter->errorInfo();
                throw new \Exception('SQL Error: ' . $array[2]);
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
     * @throws \Exception
     */
    protected function incdec($tablename, $pairs, $conditions = '', $inc = true) {
        if (!empty($pairs)) {
            $conditions = empty($conditions) ? '' : 'WHERE ' . $conditions;
            $pairupd   = array();
            foreach ($pairs as $field => $val) {
                $pairupd [] = " $field = $field " . ($inc ? '+ ' : '- ') . $val;
            }
            $upd   = implode(',', $pairupd);
            $query = "UPDATE ".static::quoteTableName($tablename)." SET $upd $conditions";
            $res   = $this->_adapter->exec($query);
            if ($res === false) {
                $array = $this->_adapter->errorInfo();
                throw new \Exception('SQL Error: ' . $array[2]);
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
            if (in_array($field['link']['relation'], array(\Som::TO_ONE, \Som::TO_ONE_NULL))) {
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
            throw new \Exception('SQL Error: ' . $array[2]);
        }

        return $res;
    }

    /**
     * Parses query conditions from string or array
     *
     * @param mixed $conditions SQL WHERE conditions as string or numeric array of strings or array of arrays
     * @param array $params Optional PDO params to pass through
     * @throws \Exception
     * @return array SQL WHERE part and PDO params
     */
    public function parseConditions($tableName = null, $conditions = [], $params = [])
    {
        $joins = [];

        if (empty($conditions)) return ['', [], []];

        $where = $this->parseCondition($tableName, $conditions, $params, $joins);

        //if(!empty($where)) $where = 'WHERE '.$where;

        return array($where, $params, $joins);
    }

    /**
     * @param array $conditions
     * @param array $params
     * @param int   $i              counter
     * @return null|string
     * @throws \Exception
     */
    protected function parseCondition($tableName = null, &$conditions, &$params, &$joins, $i = 0)
    {
        $hasModel = false;
        /** @var ActiveRecord $modelClass */
        $modelClass = null;
        if ($tableName instanceof ActiveRecord) {
            $modelClass = get_class($tableName);
            $hasModel = true;

        } elseif (is_string($tableName)) {
            if(class_exists($tableName)) {
                $classReflection = new \ReflectionClass($tableName);
                $hasModel = $classReflection->isSubclassOf(ActiveRecord::class);
                if ($hasModel) {
                    $modelClass = $tableName;
                }
            }
        }

//        /** @var Model $class */
//        $class = $this->_dbinfo['class'];
//        $table = $this->_dbinfo['tbname'];
//        if(empty($table) && !empty($class))  $table = $class::tableName();
//        $tq = $this->tableQuote;

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
//                    if (!isset($condition[1])) $condition[1] = NULL;
//                    if (empty($condition[2])) $condition[2] = '=';
//                    if (empty($condition[3]) || $condition[3] != 'OR') $condition[3] = 'AND';

//                    $column = $condition[0];
//                    $value = $condition[1];
//                    $operand = $condition[2];

                    /**
                     * Первый параметр  - массив. может содержать вложенные условия.
                     * Например:
                     * $condition[]=[
                     *    [['desc', '*' . $kw . '*'],['text', '*' . $kw . '*', null, "OR"]]
                     * ];
                     */
                    if (isset($condition[0]) && is_array($condition[0])) {
                        $wh = $this->parseCondition($tableName, $condition[0], $params, $joins, $i);

                    } else {
                        if(count($condition) == 2) {
                            $column = $condition[0];
                            $operand = '=';
                            $value = $condition[1];
                            $boolean = 'AND';

                        } else {
                            $column = $condition[0];
                            $operand = $condition[1];
                            $value = $condition[2];
                            $boolean = (!isset($condition[3]) || $condition[3] != 'OR') ? $condition[3] : 'AND';
                        }

                        if (mb_strpos($column, '.') !== false) {
                            $tmp = explode('.', $column);
                            $tmp[0] = ($tmp[0] != '') ? $tmp[0] : $tableName;

                            $tblCol = !empty($tmp[0]) ? $tmp[0].'.'.$tmp[1] : $tmp[1];
                            $tblCol = $this->quoteColumnName($tblCol);

                            $column = $tmp[1];

                        } else {
                            $tblCol = !empty($tableName) ? $tableName.'.'.$column : $column;
                            $tblCol = $this->quoteColumnName($tblCol);
                        }


                        // Если передали объект
                        // ==== не нужно ====
//                        if ($condition[0] instanceof ActiveRecord && !empty($modelClass)) {
//                            $fields = $modelClass::fieldList();
//                            // todo дописать обработку полей
//                            if (empty($condition[1])) {
//                                // Не передали поле, ищем первое попавшиеся
//                                $fld = $modelClass::field($condition[0]);
//                                // todo exception
//                                if (empty($fld)) throw new \Exception("В моделе '{$modelClass}' нет связи с '" .
//                                    get_class($condition[0]) . "'");
//
//                            } else {
//                                $fld = $modelClass::field($condition[1]);
//                                if (empty($fld)) {
//                                    throw new \Exception("В моделе '{$modelClass}' поле '{$condition[1]}' не найдено");
//                                }
//                                if (!is_a($condition[0], $fld['model'])) {
//                                    throw new \Exception("В условии выборки переданный объект класса '" . get_class($condition[0]) . "' не
//                                соответствует модели '{$fld['model']}' связанной c полем '{$condition[1]}'");
//                                }
//                            }
//                            $value = (int)$condition[0]->getId();
//                            $column = $fld['name'];
//                        }
                        // ==== /не нужно ====

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

                    if ($boolean != 'OR') {
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
                        $sql = (!empty($tableName)) ? static::quoteTableName($tableName).'.'.static::quoteColumnName($column) :
                            static::quoteColumnName($column);

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
     * @throws \Exception
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
                            throw new \Exception("Wrong order direct '{$cond[1]}'. Must be 'ASC', 'DESC' or empty ");
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
     * Quotes a string value for use in a query.
     * 
     * @param string|array $data string or strings array for quotting

     * @return array|string the properly quoted string or array of strings
     * @see http://php.net/manual/en/pdo.quote.php
     */
    public function quote($data)
    {
        if (is_string($data)) {
            if (($value = $this->_adapter->quote($data)) !== false) {
                return $value;
            }

            // the driver doesn't support quote (e.g. oci)
            return "'" . addcslashes(str_replace("'", "''", $data), "\000\n\r\\\032") . "'";
        }

        if (!is_array($data)) return $data;

        foreach ($data as $key => $str) {
            if (!(strval(intval($data[$key])) == $data[$key])) $data[$key] = $this->quote($str);
        }

        return $data;
    }

    /**
     * Alias for quote()
     * @return array|string
     * @see quote()
     */
    public function quoteValue($value)
    {
        return $this->quote($value);
    }

    /**
     * Returns the symbol the adapter uses for delimited identifiers.
     *
     * @return string|array
     */
    public function getTableQuoteCharacter()
    {
        return $this->tableQuoteCharacter;
    }


    /**
     * Quotes a table name for use in a query.
     * If the table name contains schema prefix, the prefix will also be properly quoted.
     *
     * If the table name is already quoted or contains special characters including '(',
     * then this method will do nothing.
     *
     * @param string $name table name
     * @return string the properly quoted table name
     */
    public function quoteTableName($name)
    {
        if (strpos($name, '(') !== false) return $name;

        if (strpos($name, '.') === false) {
            return $this->quoteSimpleTableName($name);
        }

        $parts = explode('.', $name);
        foreach ($parts as $i => $part) {
            $parts[$i] = $this->quoteSimpleTableName($part);
        }

        return implode('.', $parts);
    }

    /**
     * Quotes a simple table name for use in a query.
     * A simple table name should contain the table name only without any schema prefix.
     * If the table name is already quoted, this method will do nothing.
     *
     * @param string $name table name
     * @return string the properly quoted table name
     */
    public function quoteSimpleTableName($name)
    {
        if (is_string($this->tableQuoteCharacter)) {
            $startChar = $endChar = $this->tableQuoteCharacter;

        } else {
            list($startChar, $endChar) = $this->tableQuoteCharacter;
        }

        if(strpos($name, $startChar) !== false) return $name;

        // Not sure if it is really needed
//        $name = str_replace($startChar, $startChar.$startChar, $name);
//        if($startChar != $endChar) {
//            $name = str_replace($endChar, $endChar.$endChar, $name);
//        }

        return $startChar . $name . $endChar;
    }

    /**
     * Quotes a column name for use in a query.
     * If the column name contains prefix, the prefix will also be properly quoted.
     * If the column name is already quoted or contains special characters including '(', '[['
     * then this method will do nothing.
     *
     * @param string $name column name
     * @return string the properly quoted column name
     */
    public function quoteColumnName($name)
    {
        if (strpos($name, '(') !== false || strpos($name, '[[') !== false) {
            return $name;
        }

        if (($pos = strrpos($name, '.')) !== false) {
            $prefix = $this->quoteTableName(substr($name, 0, $pos)) . '.';
            $name = substr($name, $pos + 1);

        } else {
            $prefix = '';
        }

        return $prefix . $this->quoteSingleColumnName($name);
    }

    /**
     * Quotes a simple column name for use in a query.
     * A simple column name should contain the column name only without any prefix.
     * If the column name is already quoted or is the asterisk character '*', this method will do nothing.
     *
     * @param string $name column name
     * @return string the properly quoted column name
     */
    public function quoteSingleColumnName($name)
    {
        if (is_string($this->tableQuoteCharacter)) {
            $startChar = $endChar = $this->columnQuoteCharacter;

        } else {
            list($startChar, $endChar) = $this->columnQuoteCharacter;
        }

        if($name === '*' || strpos($name, $startChar) !== false) return $name;

        return $startChar . $name . $endChar;
    }

    /**
     * Binds parameters to a statement
     *
     * @param \PDOStatement $statement PDO statement
     * @param array $parameters Array of parameters, numeric or associative
     * @throws \Exception
     */
    private function _bindParams($statement, $parameters)
    {
        $is_numeric = is_int(key($parameters));
        foreach ($parameters as $key => $val) {
            $type = is_int($val) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $is_numeric ? $statement->bindValue($key + 1, $val, $type) : $statement->bindValue($key, $val, $type);
            if ($statement === false) {
                $array = $this->_adapter->errorInfo();
                throw new \Exception('SQL Error: ' . $array[2]);
            }
        }
    }

    /**
     * Load relationship
     *
     * @param string|ActiveRecord $xModel
     * @param $id
     * @param string $fieldName
     * @return array|bool|null
     * @throws \Exception
     *
     * @todo it seems we don't need this method any more
     */
//    public function loadXRef($xModel, $id, $fieldName = '')
//    {
//        if (is_string($xModel) || ($xModel instanceof ActiveRecord)) {
//            /** @var ActiveRecord $xModel */
//            $xPk = $xModel::primaryKey();
//            $linkModelDbInfo = $xModel::getDbConfig();
//
//            $xTableName = $linkModelDbInfo['tbname'];
//
//        } else {
//            return false;
//        }
//
//        $tableName = $this->_dbinfo['tbname'];
//
//        $pk = $this->_dbinfo['pkey'];
//
//        $junctionTable = $this->junctionTable($tableName, $xTableName, $pk, $xPk);
//        if(!$this->tableExists($junctionTable['name'])) return null;
//
//        $query = "SELECT ".$this->quoteIdentifier($junctionTable['relatedKey']).
//            " FROM ".$this->quoteIdentifier($junctionTable['name']).
//            " WHERE ".$this->quoteIdentifier($junctionTable['ownerKey'])."={$id} AND ".
//            $this->quoteIdentifier('name')."=".$this->quote($fieldName);
//
//        $xRefs = $this->query($query)->fetchAll(\PDO::FETCH_COLUMN);
//
//        if (count($xRefs) <= 0) return null;
//
//        return $xRefs;
//    }

    /**
     * Save relationship between two models
     *
     * @param string|ActiveRecord $xModel
     * @param $id
     * @param $data
     * @param string $fieldName
     * @return bool
     * @throws \Exception
     */
//    protected function saveXRef($xModel, $id, $data, $fieldName = '')
//    {
//        if (is_string($xModel) || ($xModel instanceof ActiveRecord)) {
//            /** @var ActiveRecord $xModel */
//            $xPk = $xModel::primaryKey();
//            $linkModelDbInfo = $xModel::getDbConfig();
//
//            $xTableName = $linkModelDbInfo['tbname'];
//
//        } else {
//            return false;
//        }
//
//        $tableName = $this->_dbinfo['tbname'];
//        $pk = $this->_dbinfo['pkey'];
//
//        return $this->saveRelations($tableName, $xTableName, $id, $data, $fieldName, $pk, $xPk);
//    }

    /**
     * Save relationship between two DB tables
     *
     * @param $table
     * @param $relatedTable
     * @param $pkey
     * @param $relatedPkey
     * @param $id
     * @param $data
     * @param $fieldName
     * @return bool
     * @throws \Exception
     *
     * @todo add indexes by key's fields
     */
    public function saveRelations($table, $relatedTable, $id, $data, $fieldName = '',  $pkey = 'id', $relatedPkey = 'id')
    {
        $junctionTable = $this->junctionTable($table, $relatedTable, $pkey, $relatedPkey);

        $priKey  = $junctionTable['ownerKey'];
        $relatedPriKey = $junctionTable['relatedKey'];

        // Create junction table if it is not exists
        $isNewTable = false;
        if(!$this->tableExists($junctionTable['name'])) {
            $this->createTable($junctionTable['name'], [
                'xref_id' => [
                    'name' => 'xref_id',
                    'type' => 'int',
                    'primary' => true,
                ],
                $priKey => [
                    'name' => $priKey,
                    'type' => 'int',
                ],
                $relatedPriKey => [
                    'name' => $relatedPriKey,
                    'type' => 'int',
                ],
                'name' => [
                    'name' => 'name',
                    'type' => 'varchar',
                ]
            ]);

            $isNewTable = true;
        }

        // Load existing relations
        if(!$isNewTable) {
            $query = "SELECT " . $this->quoteColumnName($relatedPriKey) . " FROM {$junctionTable['name']} WHERE " .
                $this->quoteColumnName($priKey) . "={$id} AND " .
                $this->quoteColumnName('name') . "= ?";

            $old_xRefs = $this->query($query, [$fieldName])->fetchAll(\PDO::FETCH_COLUMN);
        }
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

        // Remove old relations that have been removed
        $rem_xRefs = array_diff($old_xRefs, $kept_xRefs);
        if (count($rem_xRefs) > 0) {
            $inCond = "(" . implode(",", $this->quote($rem_xRefs)) . ")";
            $this->delete($junctionTable['name'], $this->quoteColumnName($priKey).'=$id '.
                "AND {$relatedPriKey} IN $inCond ".
                'AND '.$this->quoteColumnName('name')."=".$this->quote($fieldName));
        }

        // Add new relations
        foreach ($new_xRefs as $item) {
            $isStr = !is_int($item) && !is_float($item) && is_string($item);

            if ((!$isStr && $item > 0) || ($isStr && $item != '')) {
                $upData = array(
                    $priKey => $id,
                    $relatedPriKey => $item,
                    'name' => $fieldName
                );
                $res = $this->insert($junctionTable['name'], $upData);

                if ($res === false) {
                    $error = $this->_adapter->errorInfo();
                    throw new \Exception("SQL Error {$error[0]}: {$error[2]}");
                };
            }
        }

        return true;
    }

    /**
     * Destroys the relationship between two models.
     *
     * @param string|ActiveRecord $xModel
     * @param $id
     * @param string $fieldName
     * @return bool
     *
     * @deprecated
     */
//    protected function deleteXRef($xModel, $id, $fieldName = '')
//    {
//        if (is_string($xModel) || ($xModel instanceof ActiveRecord)) {
//            /** @var ActiveRecord $xModel */
//            $xPk        = $xModel::primaryKey();
//            $xTableName = $xModel::tableName();
//
//        } else {
//            return false;
//        }
//
//        $tableName  = $this->_dbinfo['tbname'];
//        $pk         = $this->_dbinfo['pkey'];
//
//        $this->deleteRelations($tableName, $xTableName, $id, $fieldName, $pk, $xPk);
//    }

    /**
     * Destroys the relationship between two DB tables.
     *
     * @param string $table
     * @param string $relatedTable  Связь с этой таблицей будет удалена
     * @param int    $id            Primary key value from the $table
     * @param string $fieldName
     * @param string $pkey
     * @param string $relatedPkey
     * @throws \Exception
     */
    public function deleteRelations($table, $relatedTable, $id, $fieldName = '',  $pkey = 'id', $relatedPkey = 'id')
    {
        $junctionTable = $this->junctionTable($table, $relatedTable, $pkey, $relatedPkey);

        $priKey = $junctionTable['ownerKey'];

        if ($this->tableExists($junctionTable['name'])) {
            $condition = $this->quoteColumnName($junctionTable['name'].'.'.$priKey).'='.$id;
            if(!is_null($fieldName)) {
                $condition .= ' AND '.$this->quoteColumnName('name').'='.$this->quote($fieldName);
            }
            $this->delete($junctionTable['name'], $condition);
        }
    }

    /**
     * Get the joining table name for a many-to-many relation.
     *
     * The junction table name, by convention, is simply the table names
     * sorted alphabetically and concatenated with an underscore.
     *
     * @param string $tableName
     * @param string $relatedTable
     * @param string $primaryKey
     * @param string $relatedPrimaryKey
     * @return array
     */
    public function junctionTable($tableName, $relatedTable, $primaryKey = 'id', $relatedPrimaryKey = 'id')
    {
        /** @var ActiveRecord $modelClass */
//        $modelClass = null;
//        if ($tableName instanceof ActiveRecord) {
//            $modelClass = get_class($tableName);
//
//        } elseif (is_string($tableName)) {
//            if(class_exists($tableName)) {
//                $classReflection = new \ReflectionClass($tableName);
//                if ($classReflection->isSubclassOf(ActiveRecord::class)) {
//                    $modelClass = $tableName;
//                }
//            }
//        }
//
//        if(!empty($modelClass)) {
//            $tableName = $modelClass::tableName();
//        }

        $prefix = $this->tablePrefix;

        // Remove prefixes of table names
        $tableName = preg_replace("/^$prefix/iu", '', $tableName);
        $relatedTable = preg_replace("/^$prefix/iu", '', $relatedTable);

        $tables = [
            $tableName,
            $relatedTable,
        ];

        sort($tables);

        $ret = [
            'name' => $prefix.strtolower(implode('_', $tables)),
            'ownerKey' => mb_strtolower(Inflector::singularize($tableName) . '_' . $primaryKey),
            'relatedKey' => mb_strtolower(Inflector::singularize($relatedTable) . '_' . $relatedPrimaryKey),
        ];

//        if(!empty($modelClass)) {
//            $foreignKey = $modelClass::foreignKey();
//        }

        return $ret;
    }

    /**
     * Parses PDO exception message and returns its components and status
     *
     * @param \PDOException $e PDO Exception
     * @param string $err_code Output error code parameter
     * @param string $err_message Output error message parameter
     * @return bool TRUE for error cases, FALSE for notifications and warnings
     */
    private function _parseError(\PDOException $e, &$err_code, &$err_message)
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


//    public function getDbInfo()
//    {
//        return $this->_dbinfo;
//    }
}
