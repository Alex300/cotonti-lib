<?php

/**
 *
 * PostgresSql Mapper
 * @author Kalnov Alexey http://portal30.ru
 */
class Som_Model_Mapper_Pgsql extends Som_Model_Mapper_Abstract {

    protected $tableQuote = '"';

//    public function getAdapter(){
//        return Kernel::getDB();
//    }

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
     * @todo Discover integer unsigned property.
     *
     * @param  string $tableName
     * @param  string $schemaName OPTIONAL
     * @return array
     */
    public function describeTable($tableName, $schemaName = null)
    {
        $sql = "SELECT
                a.attnum,
                n.nspname,
                c.relname,
                a.attname AS colname,
                t.typname AS type,
                a.atttypmod,
                FORMAT_TYPE(a.atttypid, a.atttypmod) AS complete_type,
                d.adsrc AS default_value,
                a.attnotnull AS notnull,
                a.attlen AS length,
                co.contype,
                ARRAY_TO_STRING(co.conkey, ',') AS conkey
            FROM pg_attribute AS a
                JOIN pg_class AS c ON a.attrelid = c.oid
                JOIN pg_namespace AS n ON c.relnamespace = n.oid
                JOIN pg_type AS t ON a.atttypid = t.oid
                LEFT OUTER JOIN pg_constraint AS co ON (co.conrelid = c.oid
                    AND a.attnum = ANY(co.conkey) AND co.contype = 'p')
                LEFT OUTER JOIN pg_attrdef AS d ON d.adrelid = c.oid AND d.adnum = a.attnum
            WHERE a.attnum > 0 AND c.relname = ".$this->quote($tableName);
        if ($schemaName) {
            $sql .= " AND n.nspname = ".$this->quote($schemaName);
        }
        $sql .= ' ORDER BY a.attnum';

        $stmt = $this->query($sql);

        // Use FETCH_NUM so we are not dependent on the CASE attribute of the PDO connection
        $result = $stmt->fetchAll(PDO::FETCH_NUM);

        $attnum        = 0;
        $nspname       = 1;
        $relname       = 2;
        $colname       = 3;
        $type          = 4;
        $atttypemod    = 5;
        $complete_type = 6;
        $default_value = 7;
        $notnull       = 8;
        $length        = 9;
        $contype       = 10;
        $conkey        = 11;

        $desc = array();
        foreach ($result as $key => $row) {
            $defaultValue = $row[$default_value];
            if ($row[$type] == 'varchar' || $row[$type] == 'bpchar' ) {
                if (preg_match('/character(?: varying)?(?:\((\d+)\))?/', $row[$complete_type], $matches)) {
                    if (isset($matches[1])) {
                        $row[$length] = $matches[1];
                    } else {
                        $row[$length] = null; // unlimited
                    }
                }
                if (preg_match("/^'(.*?)'::(?:character varying|bpchar)$/", $defaultValue, $matches)) {
                    $defaultValue = $matches[1];
                }
            }
            list($primary, $primaryPosition, $identity) = array(false, null, false);
            if ($row[$contype] == 'p') {
                $primary = true;
                $primaryPosition = array_search($row[$attnum], explode(',', $row[$conkey])) + 1;
                $identity = (bool) (preg_match('/^nextval/', $row[$default_value]));
            }
            $desc[mb_strtolower($row[$colname])] = array(
                'SCHEMA_NAME'      => mb_strtolower($row[$nspname]),
                'TABLE_NAME'       => mb_strtolower($row[$relname]),
                'COLUMN_NAME'      => mb_strtolower($row[$colname]),
                'COLUMN_POSITION'  => $row[$attnum],
                'DATA_TYPE'        => $row[$type],
                'DEFAULT'          => $defaultValue,
                'NULLABLE'         => (bool) ($row[$notnull] != 't'),
                'LENGTH'           => $row[$length],
                'SCALE'            => null, // @todo
                'PRECISION'        => null, // @todo
                'UNSIGNED'         => null, // @todo
                'PRIMARY'          => $primary,
                'PRIMARY_POSITION' => $primaryPosition,
                'IDENTITY'         => $identity
            );
        }
        return $desc;
    }


    /*
     * Получить наименование первичного ключа можно след. запросом
     *
     SELECT
    i.relname AS indexname,
    pg_get_indexdef(i.oid) AS indexdef
FROM pg_index x
INNER JOIN pg_class i ON i.oid = x.indexrelid
WHERE x.indrelid = '$table'::regclass::oid AND i.relkind = 'i'::"char"
AND x.indisprimary

     */

    public function lastInsertId($table_name = '', $pkey = ''){
        if(empty($table_name)){
            $table_name = $this->_dbinfo['tbname'];
            $pkey   = $this->_dbinfo['pkey'];
        }

        if(empty($pkey)){
            $sql = "SELECT a.attname AS colname FROM pg_attribute AS a
                JOIN pg_class AS c ON a.attrelid = c.oid
                 LEFT OUTER JOIN pg_constraint AS co ON (co.conrelid = c.oid
                    AND a.attnum = ANY(co.conkey) AND co.contype = 'p')
            WHERE a.attnum > 0 AND c.relname = ".$this->quote($table_name)." AND co.contype = 'p'";
            $sql .= ' ORDER BY a.attnum';

            $stmt = $this->query($sql);

            $pkey = $stmt->fetchColumn();
        }
        if(empty($pkey)) return null;

        return $this->_adapter->lastInsertId("{$table_name}_{$pkey}_seq");
    }

    /**
     * Получить существующие таблиуы
     *  Например: SHOW TABLES для MySql
     * @param string $schema
     * @return array
     */
    public function tables($schema = ''){
        if(empty($schema)) $schema = 'public';

        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = ?  ORDER BY table_name";
        $ret = $this->query($sql, $schema);
        $res = $ret->fetchAll(PDO::FETCH_COLUMN);

        return $res;
    }

    /**
     * Проверка таблицы на существование
     * @param $table
     * @return bool
     */
    public function tableExists($table = false){
        if(!$table) $table = $this->_dbinfo['tbname'];

        $res = $this->query("SELECT 1 FROM pg_catalog.pg_class WHERE relkind = 'r' AND relname = ? AND pg_catalog.pg_table_is_visible(oid) LIMIT 1", $table)->fetchAll();
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }
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
                    {$tq}{$pKey}{$tq} bigserial NOT NULL,
                    CONSTRAINT {$tq}{$table}_pkey{$tq} PRIMARY KEY ({$tq}{$pKey}{$tq})
               )";

        $res = $this->_adapter->query($sql);
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }
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

        $sql = "SELECT count(*) FROM information_schema.columns WHERE table_name = :table AND column_name = :fieldname";
        $params = array('table' => $table, 'fieldname' => $field_name);
        $fields = $this->query($sql, $params)->fetch(PDO::FETCH_COLUMN);

        return ($fields > 0) ? true : false;
    }

    public function alterField($old, $new = false){
        die('move "alterField" to postgres');

        if(empty($old)) return false;
        //if (empty($new)) $new = $old;   // Меняем определение поля

        if(is_string($old)) $old = array('name'=>$old);
        if(is_string($new)) $new = array('name'=>$new);

        if(!$this->fieldExist($old['name'])) return false;
        $adapter = $this->_adapter->getConnection();

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
                $defenition .= " DEFAULT ".$this->_adapter->quote($old['default']);
            }

            $sql = "ALTER TABLE `{$this->_dbinfo['tbname']}` MODIFY {$old['name']} $defenition";

            $res = $adapter->query($sql);
            if ($res === false) {
                $array = $this->_adapter->errorInfo();
                throw new Exception('SQL Error: ' . $array[2]);
            }
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
            $res = $adapter->query("SHOW COLUMNS FROM `{$this->_dbinfo['tbname']}` LIKE '{$old['name']}'")->fetch(PDO::FETCH_ASSOC);
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
            $defenition .= " DEFAULT ".$this->_adapter->quote($new['default']);
        }

        $sql = "ALTER TABLE `{$this->_dbinfo['tbname']}` CHANGE {$old['name']} {$new['name']} $defenition";

        $res = $adapter->query($sql);

        return $res;

    }

    public function deleteField($field){
        die('move "deleteField" to postgres');

        if(!$this->fieldExists($field)) return false;
        $adapter = $this->_adapter->getConnection();
        $adapter->query("ALTER TABLE `{$this->_dbinfo['tbname']}` DROP $field");
    }


    /**
     * Получить существующие поля
     * @param string $table
     * @return array
     */
    public function getFields($table = '') {

        if(!$table) $table = $this->_dbinfo['tbname'];

        $keyName = 'SomModelPgsqlFields' . $table;
//        if (($fields=LaCache::load($keyName))===false) {
            $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = ?";
            $fields = $this->query($sql, $table)->fetchAll(PDO::FETCH_COLUMN);
//            LaCache::save($fields, $keyName);
//        }
        return $fields;
    }

    public function renameTable($tablename = '', $newtablename=''){
        if(empty($tablename)) $tablename = $this->_dbinfo['tbname'];
        if(empty($newtablename)) $newtablename = 'renamed_'. $this->_dbinfo['tbname'] ;
        $sql = 'ALTER TABLE "'. $tablename . '" RENAME TO "'.$newtablename.'"';

        $res = $this->_adapter->query($sql);
        if ($res === false) {
            $array = $this->_adapter->errorInfo();
            throw new Exception('SQL Error: ' . $array[2]);
        }
        return $res;
    }

    /**
     * Специальный обработик bool полей для специфических баз.
     *
     * @param mixed $data
     * @return array|string
     */
    public function quote($data){
        if (is_bool($data))
            $data = $data ? 'true' : 'false';
        return parent::quote($data);
    }
}