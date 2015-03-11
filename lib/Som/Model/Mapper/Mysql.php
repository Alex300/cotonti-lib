<?php
/**
 * MySql Mapper
 * @author Kalnov Alexey http://portal30.ru
 */
class Som_Model_Mapper_Mysql extends Som_Model_Mapper_Abstract{

    protected $tableQuote = '`';

    /**
     * Connect to Data Base
     * @param $dbc
     * @return PDO
     */
    protected static function connect($dbc) {
        if (empty(Som_Model_Mapper_Abstract::$connections[$dbc])) {

            // Connect to DB
            if($dbc == 'db'){
                // Default cotonti connection
                Som_Model_Mapper_Abstract::$connections[$dbc] = cot::$db;
            }else{
                // Альтернативное соединение из конфига
                $options = array();
                if (!empty(cot::$cfg[$dbc]['charset'])) {
                    $collation_query = "SET NAMES '".cot::$cfg[$dbc]['charset']."'";
                    if (!empty(cot::$cfg[$dbc]['collate']) )  {
                        $collation_query .= " COLLATE '".cot::$cfg[$dbc]['collate']."'";
                    }
                    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = $collation_query;
                }

                $dbc_port = empty(cot::$cfg[$dbc]['port']) ? '' : ';port=' . cot::$cfg[$dbc]['port'];
                $dsn = cot::$cfg[$dbc]['adapter'] . ':host=' . cot::$cfg[$dbc]['host'] . $dbc_port .
                    ';dbname=' . cot::$cfg[$dbc]['dbname'];
                Som_Model_Mapper_Abstract::$connections[$dbc] = new PDO($dsn, cot::$cfg[$dbc]['username'],
                    cot::$cfg[$dbc]['password'], $options);
            }
        }

        return Som_Model_Mapper_Abstract::$connections[$dbc];
    }


    /**
     * Returns the column descriptions for a table.
     *
     * The return value is an associative array keyed by the column name,
     * as returned by the RDBMS.
     *
     * The value of each array element is an associative array
     * with the following keys:
     *
     * SCHEMA_NAME      => string; name of database or schema
     * TABLE_NAME       => string;
     * COLUMN_NAME      => string; column name
     * COLUMN_POSITION  => number; ordinal position of column in table
     * DATA_TYPE        => string; SQL datatype name of column
     * DEFAULT          => string; default expression of column, null if none
     * NULLABLE         => boolean; true if column can have nulls
     * LENGTH           => number; length of CHAR/VARCHAR
     * SCALE            => number; scale of NUMERIC/DECIMAL
     * PRECISION        => number; precision of NUMERIC/DECIMAL
     * UNSIGNED         => boolean; unsigned property of an integer type
     * PRIMARY          => boolean; true if column is part of the primary key
     * PRIMARY_POSITION => integer; position of column in primary key
     * IDENTITY         => integer; true if column is auto-generated with unique values
     *
     * @param string $tableName
     * @param string $schemaName OPTIONAL
     * @throws Exception
     * @return array
     */
    public function describeTable($tableName, $schemaName = null)
    {
        /**
         * @todo  use INFORMATION_SCHEMA someday when
         * MySQL's implementation isn't too slow.
         */
        $tq =    $this->tableQuote;
        if ($schemaName) {
            $sql = "DESCRIBE {$tq}{$schemaName}{$tq}.{$tq}{$tableName}{$tq}";
        } else {
            $sql = "DESCRIBE {$tq}{$tableName}{$tq}";
        }

        /**
         * Use mysqli extension API, because DESCRIBE doesn't work
         * well as a prepared statement on MySQL 4.1.
         */
        if ($queryResult = $this->query($sql)) {
            while ($row = $queryResult->fetch()) {
                $result[] = $row;
            }
            $queryResult->closeCursor();
        } else {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }

        $desc = array();

        $row_defaults = array(
            'Length'          => null,
            'Scale'           => null,
            'Precision'       => null,
            'Unsigned'        => null,
            'Primary'         => false,
            'PrimaryPosition' => null,
            'Identity'        => false
        );
        $i = 1;
        $p = 1;
        foreach ($result as $key => $row) {
            $row = array_merge($row_defaults, $row);
            if (preg_match('/unsigned/', $row['Type'])) {
                $row['Unsigned'] = true;
            }
            if (preg_match('/^((?:var)?char)\((\d+)\)/', $row['Type'], $matches)) {
                $row['Type'] = $matches[1];
                $row['Length'] = $matches[2];
            } else if (preg_match('/^decimal\((\d+),(\d+)\)/', $row['Type'], $matches)) {
                $row['Type'] = 'decimal';
                $row['Precision'] = $matches[1];
                $row['Scale'] = $matches[2];
            } else if (preg_match('/^float\((\d+),(\d+)\)/', $row['Type'], $matches)) {
                $row['Type'] = 'float';
                $row['Precision'] = $matches[1];
                $row['Scale'] = $matches[2];
            } else if (preg_match('/^((?:big|medium|small|tiny)?int)\((\d+)\)/', $row['Type'], $matches)) {
                $row['Type'] = $matches[1];
                /**
                 * The optional argument of a MySQL int type is not precision
                 * or length; it is only a hint for display width.
                 */
            }
            if (strtoupper($row['Key']) == 'PRI') {
                $row['Primary'] = true;
                $row['PrimaryPosition'] = $p;
                if ($row['Extra'] == 'auto_increment') {
                    $row['Identity'] = true;
                } else {
                    $row['Identity'] = false;
                }
                ++$p;
            }
            $desc[mb_strtolower($row['Field'])] = array(
                'SCHEMA_NAME'      => null, // @todo
                'TABLE_NAME'       => mb_strtolower($tableName),
                'COLUMN_NAME'      => mb_strtolower($row['Field']),
                'COLUMN_POSITION'  => $i,
                'DATA_TYPE'        => $row['Type'],
                'DEFAULT'          => $row['Default'],
                'NULLABLE'         => (bool) ($row['Null'] == 'YES'),
                'LENGTH'           => $row['Length'],
                'SCALE'            => $row['Scale'],
                'PRECISION'        => $row['Precision'],
                'UNSIGNED'         => $row['Unsigned'],
                'PRIMARY'          => $row['Primary'],
                'PRIMARY_POSITION' => $row['PrimaryPosition'],
                'IDENTITY'         => $row['Identity']
            );
            ++$i;
        }
        return $desc;
    }

    public function lastInsertId($table_name = '', $pkey = ''){
        return $this->_adapter->lastInsertId();
    }

    /**
     * Получить существующие таблиуы
     *  Например: SHOW TABLES для MySql
     * @param string $schema
     * @return array
     */
    public function tables($schema = ''){
        return $this->query('SHOW TABLES')->fetchColumn();
    }

    /**
     * Проверка таблицы на существование
     * @param $table
     * @return bool
     */
    public function tableExists($table = false){
        if(!$table) $table = $this->_dbinfo['tbname'];
        $res = $this->query("SHOW TABLES LIKE ".$this->quote($table))->fetchAll();
        return !empty($res);
    }

    public function createTable($table = '', $fields = array()){
        $tq =    $this->tableQuote;

        if(empty($table)) $table = $this->_dbinfo['tbname'];
        $pKey = '';
        if($table == $this->_dbinfo['tbname']){
            $pKey = $this->_dbinfo['pkey'];
        }else{
            if(is_array($fields)){
                foreach($fields as $fld){
                    if(!empty($fld['primary'])){
                        $pKey = $fld['name'];
                    }
                }
            }
        }
        if(empty($pKey)) $pKey = 'id';

        // Создать таблицу
        $sql = "CREATE TABLE IF NOT EXISTS {$tq}{$table}{$tq} (
                    {$tq}{$pKey}{$tq} int(11) unsigned NOT NULL auto_increment,
                    PRIMARY KEY  ({$tq}{$pKey}{$tq})
               )ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

        $res = $this->query($sql);

        if(is_array($fields)){
            if(array_key_exists($pKey, $fields)) unset($fields[$pKey]);
            foreach($fields as $name => $fld){
                $fld['name'] = $name;
                $this->createField($fld, $table);
            }
        }
    }


    public function fieldExists($field_name, $table = '')    {
        $tq =    $this->tableQuote;
        if(empty($table)) $table = $this->_dbinfo['tbname'];

        return $this->query("SHOW COLUMNS FROM {$tq}{$table}{$tq} WHERE Field = " . $this->quote($field_name))->rowCount() == 1;
    }

    public function alterField($old, $new = false){

        if(empty($old)) return false;
        //if (empty($new)) $new = $old;   // Меняем определение поля

        if(is_string($old)) $old = array('name'=>$old);
        if(is_string($new)) $new = array('name'=>$new);

        if(!$this->fieldExist($old['name'])) return false;

        // To change column a from INTEGER to TINYINT NOT NULL
        // ALTER TABLE t2 MODIFY a TINYINT NOT NULL
        if (empty($new)){
            $defenition = '';
            if (empty($old['type'])) return false;
            $defenition .= $old['type'];
            if(!empty($old['length'])) $defenition .= "({$old['length']})";
            if(!$old['nullable']) $defenition .= " NOT NULL";
            if($old['default'] === NULL){
                // хз пока, все зависит от типа
                //$defenition .= " DEFAULT NULL";
            }elseif(!empty($old['default'])){
                $defenition .= " DEFAULT ".$this->quote($old['default']);
            }

            $sql = "ALTER TABLE `{$this->_dbinfo['tbname']}` MODIFY {$old['name']} $defenition";

            $res = $this->query($sql);

            return $res;
        }

        // CHANGE COLUMN old_col_name new_col_name column_definition
        $defenition = '';
        if(empty($new['type']) && !empty($old['type'])) $new['type'] = $old['type'];
        if(empty($new['length']) && !empty($old['length'])) $new['length'] = $old['length'];
        if(!isset($new['nullable']) && isset($old['nullable'])) $new['nullable'] = $old['nullable'];
        if(!isset($new['default']) && isset($old['default'])) $new['default'] = $old['default'];

        if(empty($new['type']) || empty($new['length']) || !isset($new['nullable']) || !isset($new['default'])){
            // Получить данные из поля
            $res = $this->query("SHOW COLUMNS FROM `{$this->_dbinfo['tbname']}` LIKE '{$old['name']}'")->fetch(PDO::FETCH_ASSOC);
            preg_match('/([\w]+)[(]([\d]+)[)]/i', $res["Type"], $mathces);
            if(empty($new['type'])) $new['type'] = $mathces[1];
            if(empty($new['length']) && !empty($mathces[2])) $new['length'] = $mathces[2];
            if(!isset($new['nullable'])){
                $new['nullable'] = ($res['Null'] == "NO") ? true : false;
            }
            if(!isset($new['default'])) $new['default'] = $res['Default'];
        }

        $defenition = '';
        if (empty($new['type'])) return false;
        $defenition .= $new['type'];
        if(!empty($new['length'])) $defenition .= "({$new['length']})";
        if(!$new['nullable']) $defenition .= " NOT NULL";
        if($new['default'] === NULL){
            // хз пока, все зависит от типа
            //$defenition .= " DEFAULT NULL";
        }elseif(!empty($new['default'])){
            $defenition .= " DEFAULT ".$this->quote($new['default']);
        }

        $sql = "ALTER TABLE `{$this->_dbinfo['tbname']}` CHANGE {$old['name']} {$new['name']} $defenition";

        $res = $this->query($sql);

        return $res;

    }

    public function deleteField($field){

        if(!$this->fieldExists($field)) return false;
        $this->query("ALTER TABLE `{$this->_dbinfo['tbname']}` DROP $field");
    }


    /**
     * Получить существующие поля
     * @param string $table
     * @return array
     */
    public function getFields($table = '') {

        if(!$table) $table = $this->_dbinfo['tbname'];

        $tq = $this->tableQuote;

        $keyName = 'SomModelMySqlFields' . $table;
//        if (($fields=LaCache::load($keyName))===false) {
        $sql = "SHOW COLUMNS FROM {$tq}{$table}{$tq}";
        $fields = $this->query($sql)->fetchAll(PDO::FETCH_COLUMN);
//            LaCache::save($fields, $keyName);
//        }

        return $fields;
    }


    public function renameTable($tablename = '', $newtablename=''){

    }

    /**
     * Специальный обработик bool полей для специфических баз.
     *
     * @param mixed $data
     * @return array|string
     */
    public function quote($data){
        if (is_bool($data))
            $data = $data ? 1 : 0;
        return parent::quote($data);
    }
 }