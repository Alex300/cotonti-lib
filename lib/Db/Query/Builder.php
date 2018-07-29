<?php

namespace lib\Db\Query;

defined('COT_CODE') or die('Wrong URL.');

use lib\Db;
use lib\Db\Query;
use lib\Db\Adapter;
use lib\Db\Query\Expression;
use lib\Db\Query\JoinClause;

/**
 * Query Builder builds a SELECT SQL statement based on the specification given as a [[Query]] object.
 */
class Builder extends \lib\BaseObject
{
    /**
     * The grammar specific operators.
     *
     * @var array
     */
    protected $operators = [];

    /**
     * The components that make up a select clause.
     *
     * @var array
     */
    protected $queryComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'unions',
        //'lock',
    ];

    /**
     * @var Adapter|string
     */
    public $db = null;

    /**
     * The separator between different fragments of a SQL statement.
     * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
     *
     * @var string
     */
    public $separator = "\n"; //' ';


    /**
     * Constructor.
     * @param Db\Adapter|string $adapter the database connection.
     * @param array $config name-value pairs that will be used to initialize the object properties
     * @throws \Exception
     */
    public function __construct($adapter = 'db', $config = [])
    {
        if(is_string($adapter)) $adapter = Db::getAdapter($adapter);
        $this->db = $adapter;

        parent::__construct($config);
    }

    /**
     * Generates a SELECT SQL statement from a [[Query]] object.
     *
     * @param Query $query the [[Query]] object from which the SQL statement will be generated.
     * @param array $params the parameters to be bound to the generated SQL statement. These parameters will
     *      be included in the result with the additional parameters generated during the query building process.
     *
     * @return array the generated SQL statement (the first array element) and the corresponding
     *      parameters to be bound to the SQL statement (the second array element). The parameters returned
     *      include those provided in `$params`.
     *
     * @todo params может и не нужен
     */
    public function build(Query $query)
    {
        $query = $query->prepare($this);

        $clauses = $this->buildComponents($query);

        $sql = trim($this->concatenate($clauses));

        return $sql;
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @param  Query  $query
     * @return array
     */
    protected function buildComponents(Query $query)
    {
        $clauses = [];

        foreach ($this->queryComponents as $component) {
            // To compile the query, we'll spin through each component of the query and
            // see if that component exists. If it does we'll just call the compiler
            // function for the component which is responsible for making the SQL.
            //if (! is_null($query->$component)) {
            if (property_exists($query, $component) && (!is_null($query->$component) || $component == 'columns')) {
                $method = 'build'.ucfirst($component);

                if(method_exists($this, $method)) {
                    //$clauses[$component] = $this->$method($query, $query->$component);
                    $clauses[$component] = $this->$method($query);
                }
            }
        }

        return $clauses;
    }

    /**
     * Compile an aggregated select clause.
     *
     * @param Query $query
     * @return string
     */
    protected function buildAggregate(Query $query)
    {
        $aggregate = $query->aggregate;

        $columns = $aggregate['columns'];
        foreach ($columns as $key => $row) {
            if(mb_strpos($row, '*') === false) $columns[$key] = $this->db->quoteColumnName($row);
        }

        $column = implode(', ', $columns);

        // If the query has a "distinct" constraint and we're not asking for all columns
        // we need to prepend "distinct" onto the column name so that the query takes
        // it into account when it performs the aggregating operations on the data.
        if ($query->distinct && $column !== '*') {
            $column = 'distinct '.$column;
        }

        return 'SELECT '.mb_strtoupper($aggregate['function']).'('.$column.') as aggregate';
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * @param Query $query
     * @return string|null
     */
    protected function buildColumns(Query $query)
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses, as it will need some
        // more syntax that is best handled by that function to keep things neat.
        if (! is_null($query->aggregate))  return '';

        $select = $query->distinct ? 'SELECT DISTINCT' : 'SELECT';

        if (empty($query->columns)) return $select . ' *';

        $columns = $this->compileColumns($query->columns);

        return $select . ' ' . implode(', ', $columns);
    }

    /**
     * Prepare columns for SQL query
     *
     * @param $tables
     * @return array
     */
    protected function compileColumns($columns)
    {
        // TODO may be use isExpression() method?
        foreach ($columns as $i => $column) {
            if ($column instanceof Expression) {
                $columns[$i] = $column->getValue();
            }
        }

        foreach ($columns as $i => $column) {
            if (is_string($i)) {
                // Пока не поддерживается
                if (strpos($column, '(') === false) {
                    $column = $this->db->quoteColumnName($column);
                }
                $columns[$i] = "$column AS " . $this->db->quoteColumnName($i);
            }

            // If column contains an alias
            if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $column, $matches)) {
                $matches[1] = (strpos($column, '(') === false) ? $this->db->quoteColumnName($matches[1]) : $matches[1];
                $columns[$i] = $matches[1] . ' AS ' . $this->db->quoteColumnName($matches[2]);

            } else {
                if (strpos($column, '(') === false) {
                    $columns[$i] = $this->db->quoteColumnName($column);
                }
            }
        }

        return $columns;
    }

    /**
     * @param Query $query
     * @param array $params the binding parameters to be populated
     * @return string the FROM clause built from [[Query::$from]].
     */
    protected function buildFrom(Query $query)
    {
        if (empty($query->from))  return '';

        $tables = $this->compileTables($query->from);

        return 'FROM ' . implode(', ', $tables);
    }

    /**
     * Prepare tables for SQL query
     * @param $tables
     * @return array
     */
    protected function compileTables($tables)
    {
        if(is_string($tables)) $tables = [$tables];

        foreach ($tables as $i => $table) {
            // TODO may be use isExpression() method?
            if ($table instanceof Expression) {
                $tables[$i] = $table->getValue();
            }
        }

        foreach ($tables as $i => $table) {
            // If $table contains an alias
            if(preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $table, $matches)) {
                $matches[1] = (strpos($table, '(') === false) ? $this->db->quoteTableName($matches[1]) : $matches[1];
                $tables[$i] = $matches[1] . ' AS ' . $this->db->quoteTableName($matches[2]);

            } else {
                if (strpos($table, '(') === false) {
                    $tables[$i] = $this->db->quoteTableName($table);
                }
            }
        }

        return $tables;
    }

    /**
     * Compile the "join" portions of the query.
     *
     * @param  Query  $query
     * @param  array  $joins
     * @return string
     */
    protected function buildJoins(Query $query, $joins = null)
    {
        if($joins === null) $joins = $query->joins;

        foreach ($joins as $i => $join) {
            $tables = $this->compileTables($join->table);
            $table = implode(', ', $tables);

            $nestedJoins = is_null($join->joins) ? '' : ' '.$this->buildJoins($query, $join->joins);

            $joins[$i] = trim(mb_strtoupper($join->type)." JOIN {$table}{$nestedJoins} {$this->buildWheres($join)}");
        }

        return implode($this->separator, $joins);
    }

    /**
     * Compile the "where" portions of the query.
     *
     * @param  Query  $query
     * @return string
     */
    protected function buildWheres(Query $query)
    {
        // Each type of where clauses has its own compiler function which is responsible
        // for actually creating the where clauses SQL. This helps keep the code nice
        // and maintainable since each clause has a very small method that it uses.
        if (is_null($query->wheres)) {
            return '';
        }

        // If we actually have some where clauses, we will strip off the first boolean
        // operator, which is added by the query builders for convenience so we can
        // avoid checking for the first clauses in each of the compilers methods.
        if (count($sql = $this->compileWheres($query)) > 0) {
            return $this->concatenateWhereClauses($query, $sql);
        }

        return '';
    }

    /**
     * Get an array of all the where clauses for the query.
     *
     * @param  Query  $query
     * @return array
     */
    protected function compileWheres($query)
    {
        $ret = [];

        foreach ($query->wheres as $where) {
            $ret[] = $where['boolean'].' '.$this->{"where{$where['type']}"}($query, $where);
        }

        return $ret;
    }

    /**
     * Format the where clause statements into one string.
     *
     * @param  Query  $query
     * @param  array  $sql
     * @return string
     */
    protected function concatenateWhereClauses($query, $sql)
    {
        $conjunction = $query instanceof JoinClause ? 'ON' : 'WHERE';

        return $conjunction.' '.$this->removeLeadingBoolean(implode(' ', $sql));
    }

    /**
     * Compile a raw where clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereRaw(Query $query, $where)
    {
        return $where['sql'];
    }

    /**
     * Compile a basic where clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBasic(Query $query, $where)
    {
        $value = $this->parameter($where['value']);

        return $this->db->quoteColumnName($where['column']).' '.$where['operator'].' '.$value;
    }

    /**
     * Compile a "where in" clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereIn(Query $query, $where)
    {
        if (!empty($where['values'])) {
            $values = implode(', ', array_map([$this, 'parameter'], $where['values']));

            return $this->db->quoteColumnName($where['column']).' IN ('.$values.')';
        }

        return '0 = 1';
    }

    /**
     * Compile a "where not in" clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotIn(Query $query, $where)
    {
        if (! empty($where['values'])) {
            $values = implode(', ', array_map([$this, 'parameter'], $where['values']));

            return $this->db->quoteColumnName($where['column']).' NOT IN ('.$values.')';
        }

        return '1 = 1';
    }

    /**
     * Compile a where in sub-select clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereInSub(Builder $query, $where)
    {
        return $this->db->quoteColumnName($where['column']).' IN ('.$this->build($where['query']).')';
    }

    /**
     * Compile a where not in sub-select clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotInSub(Query $query, $where)
    {
        return $this->db->quoteColumnName($where['column']).' NOT IN ('.$this->build($where['query']).')';
    }

    /**
     * Compile a "where null" clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNull(Query $query, $where)
    {
        return $this->db->quoteColumnName($where['column']).' IS NULL';
    }

    /**
     * Compile a "where not null" clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotNull(Query $query, $where)
    {
        return $this->db->quoteColumnName($where['column']).' is not null';
    }

    /**
     * Compile a "between" where clause.
     *
     * @param  Query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBetween(Query $query, $where)
    {
        $between = $where['not'] ? 'NOT BETWEEN' : 'BETWEEN';

        $min = $this->parameter(reset($where['values']));
        $max = $this->parameter(end($where['values']));

        return $this->db->quoteColumnName($where['column']).' '.$between.' '.$min.' and '.$max;
    }

    /**
     * Compile a where clause comparing two columns..
     *
     * @param  Query  $query  $query
     * @param  array  $where
     * @return string
     */
    protected function whereColumn(Query $query, $where)
    {
        return $this->db->quoteColumnName($where['first']).' '.$where['operator'].' '.
            $this->db->quoteColumnName($where['second']);
    }

    /**
     * Compile a nested where clause.
     *
     * @param  Query $query
     * @param  array  $where
     * @return string
     */
    protected function whereNested(Query $query, $where)
    {
        // Here we will calculate what portion of the string we need to remove. If this
        // is a join clause query, we need to remove the "on" portion of the SQL and
        // if it is a normal query we need to take the leading "where" of queries.
        $offset = $query instanceof JoinClause ? 3 : 6;

        return '('.substr($this->buildWheres($where['query']), $offset).')';
    }

    /**
     * Compile a where condition with a sub-select.
     *
     * @param  Query $query
     * @param  array   $where
     * @return string
     */
    protected function whereSub(Query $query, $where)
    {
        $select = $this->build($where['query']);

        return $this->db->quoteColumnName($where['column']).' '.$where['operator']." ($select)";
    }

    /**
     * Compile a where exists clause.
     *
     * @param  Query $query
     * @param  array  $where
     * @return string
     */
    protected function whereExists(Query $query, $where)
    {
        return 'EXISTS ('.$this->build($where['query']).')';
    }

    /**
     * Compile a where exists clause.
     *
     * @param  Query $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotExists(Query $query, $where)
    {
        return 'NOT EXISTS ('.$this->build($where['query']).')';
    }

    /**
     * Compile a where row values condition.
     *
     * @param  Query $query
     * @param  array  $where
     * @return string
     */
    protected function whereRowValues(Query $query, $where)
    {
        $values = implode(', ', array_map([$this, 'parameter'], $where['values']));
        $columns = array_map([$this->db, 'quoteColumnName'], $where['columns']);

        return '('.implode(', ', $columns).') '.$where['operator'].' ('.$values.')';
    }

    /**
     * Compile the "group by" portions of the query.
     *
     * @param  Query  $query
     * @return string
     */
    protected function buildGroups(Query $query)
    {
        $groups = $query->groups;
        if(empty($groups)) return '';

        return 'GROUP BY '.implode(', ', $this->compileColumns($groups));
    }

    /**
     * Compile the "having" portions of the query.
     *
     * @param  Query  $query
     * @return string
     */
    protected function buildHavings(Query $query)
    {
        $havings = $query->havings;
        if(empty($havings)) return '';

        $sql = implode(' ', array_map([$this, 'compileHaving'], $havings));

        return 'HAVING '.$this->removeLeadingBoolean($sql);
    }

    /**
     * Compile a single having clause.
     *
     * @param  array   $having
     * @return string
     */
    protected function compileHaving(array $having)
    {
        // If the having clause is "raw", we can just return the clause straight away
        // without doing any more processing on it. Otherwise, we will compile the
        // clause into SQL based on the components that make it up from builder.
        if ($having['type'] === 'Raw') {
            return $having['boolean'].' '.$having['sql'];
        }

        return $this->compileBasicHaving($having);
    }

    /**
     * Compile a basic having clause.
     *
     * @param  array   $having
     * @return string
     */
    protected function compileBasicHaving($having)
    {
        $column = $this->db->quoteColumnName($having['column']);

        $parameter = $this->parameter($having['value']);

        return $having['boolean'].' '.$column.' '.$having['operator'].' '.$parameter;
    }

    /**
     * Compile the "order by" portions of the query.
     *
     * @param  Query  $query
     * @param  array  $orders
     * @return string
     */
    protected function buildOrders(Query $query, $orders = null)
    {
        if(empty($orders)) $orders = $query->orders;
        if(empty($orders)) return '';

        $orders = $this->compileOrders($orders);

        return 'ORDER BY '.implode(', ', $orders);

    }

    /**
     * Compile the query orders to an array.
     *
     * @param  array  $orders
     * @return array
     */
    protected function compileOrders($orders)
    {
        foreach ($orders as $i => $order) {
            if(isset($order['sql'])) {
                $orders[$i] = $order['sql'];

            } else {
                $orders[$i] = $this->db->quoteColumnName($order['column']).' '.strtoupper($order['direction']);
            }
        }

        return $orders;
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param Query $query
     * @param  int  $limit
     * @return string
     */
    protected function buildLimit(Query $query, $limit = null)
    {
        if(empty($limit)) $limit = $query->limit;
        if(empty($limit)) return '';

        return 'LIMIT '.(int) $limit;
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param Query $query
     * @param  int  $offset
     * @return string
     */
    protected function buildOffset(Query $query, $offset = null)
    {
        if(empty($offset)) $offset = $query->offset;
        if(empty($offset)) return '';

        return 'OFFSET '.(int) $query->offset;
    }

    /**
     * Compile the "union" queries attached to the main query.
     *
     * @param  Query  $query
     * @return string
     */
    protected function buildUnions(Query $query)
    {
        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        if (! empty($query->unionOrders)) {
            $sql .= ' '.$this->buildOrders($query, $query->unionOrders);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->buildLimit($query, $query->unionLimit);
        }

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->buildOffset($query, $query->unionOffset);
        }

        return ltrim($sql);
    }

    /**
     * Compile a single union statement.
     *
     * @param  array  $union
     * @return string
     */
    protected function compileUnion(array $union)
    {
        $conjunction = $union['all'] ? ' union all ' : ' union ';

        return $conjunction.$union['query']->toSql();
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param  string  $seed
     * @return string
     */
    public function buildRandom($seed)
    {
        return 'RANDOM()';
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param Query $query
     * @return string
     */
    public function buildExists(Query $query)
    {
        $select = $this->build($query);

        return "SELECT EXISTS({$select}) AS {$this->db->quoteColumnName('exists')}";
    }

    /**
     * Get the appropriate query parameter place-holder for a value.
     *
     * @param  mixed   $value
     * @return string
     */
    public function parameter($value)
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }

    /**
     * Determine if the given value is a raw expression.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isExpression($value)
    {
        return $value instanceof Expression;
    }

    /**
     * Get the value of a raw expression.
     *
     * @param  Expression  $expression
     * @return string
     */
    public function getValue($expression)
    {
        return $expression->getValue();
    }

    /**
     * Concatenate an array of segments, removing empties.
     *
     * @param  array   $segments
     * @return string
     */
    protected function concatenate($segments)
    {
        return implode($this->separator, array_filter($segments, function ($value) {
            return (string) $value !== '';
        }));
    }

    /**
     * Remove the leading boolean from a statement.
     *
     * @param  string  $value
     * @return string
     */
    protected function removeLeadingBoolean($value)
    {
        return preg_replace('/and |or /i', '', $value, 1);
    }

    /**
     * Get the grammar specific operators.
     * @todo get if from adapter
     * @return array
     */
    public function getOperators()
    {
        return $this->operators;
    }
}