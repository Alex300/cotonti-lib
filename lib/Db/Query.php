<?php

namespace lib\Db;

defined('COT_CODE') or die('Wrong URL.');

use Closure;
use lib\Db;
use lib\Component;
use lib\Db\Query\Expression;
use lib\Db\Query\JoinClause;

use Exception\InvalidArgumentException;

/**
 * Query represents a SELECT SQL statement in a way that is independent of DBMS.
 *
 * Query provides a set of methods to facilitate the specification of different clauses
 * in a SELECT statement. These methods can be chained together.
 *
 * @todo LOCK support
 * @todo JSON support
 * @todo transaction support (or add it to adapter)
 */
class Query extends Component
{
    /**
     * The current query value bindings.
     *
     * @var array
     */
    public $bindings = [
        'select' => [],
        'from'   => [],
        'join'   => [],
        'where'  => [],
        'having' => [],
        'order'  => [],
        'union'  => [],
    ];

    /**
     * An aggregate function and column to be run.
     *
     * @var array
     */
    public $aggregate;

    /**
     * The columns that should be returned.
     *
     * @var array
     */
    public $columns = [];

    /**
     * Additional option that should be appended to the 'SELECT' keyword. For example,
     * in MySQL, the option 'SQL_CALC_FOUND_ROWS' can be used.
     *
     * $todo implement
     *
     * @var string
     */
    public $selectOption;

    /**
     * Indicates if the query returns distinct results.
     *
     * @var bool
     */
    public $distinct = false;

    /**
     * The table which the query is targeting.
     *
     * @var string
     */
    public $from;

    /**
     * The table joins for the query.
     *
     * @var array
     */
    public $joins;

    /**
     * The where constraints for the query.
     *
     * @var array
     */
    public $wheres = [];

    /**
     * The groupings for the query.
     *
     * @var array
     */
    public $groups;

    /**
     * The having constraints for the query.
     *
     * @var array
     */
    public $havings;

    /**
     * The orderings for the query.
     *
     * @var array
     */
    public $orders;

    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    public $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    public $offset;

    /**
     * The query union statements.
     *
     * @var array
     */
    public $unions;

    /**
     * The maximum number of union records to return.
     *
     * @var int
     */
    public $unionLimit;

    /**
     * The number of union records to skip.
     *
     * @var int
     */
    public $unionOffset;

    /**
     * The orderings for the union query.
     *
     * @var array
     */
    public $unionOrders;

    /**
     * Indicates whether row locking is being used.
     *
     * @var string|bool
     */
    public $lock;


    /**
     * the name of the column by which the query results should be indexed by.
     * This can also be a callable (e.g. anonymous function) that returns the index value based on the given
     * row data. For more details, see indexBy(). This property is only used by
     *
     * @var string|callable
     */
    public $indexBy;

    /**
     * The database adapter instance.
     *
     * @var Adapter
     */
    protected $db;

    /**
     * The query builder instance.
     *
     * @var \Db\Query\Builder
     */
    protected $builder;

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    public $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*',
    ];

    /**
     * Query constructor.
     * @param Adapter|string $adapter
     * @param array $config
     *
     * Don't add throws tag ! )
     */
    public function __construct($adapter = 'db', $config = [])
    {
        if(is_string($adapter)) $adapter = Db::adapter($adapter);
        $this->db = $adapter;

        $this->builder = $adapter->getBuilder();

        parent::__construct($config);
    }

    /**
     * Sets the SELECT part of the query.
     *
     * @param string|array $columns the columns to be selected.
     *
     * Example:
     * ```php
     * (new Query())->select('page_id as id', new Expression("CONCAT(page_cat, ' ', page_title) as concat_result"), 'page_state')
     * ```
     *
     * @return $this the query object itself
     *
     * @see https://laravel.com/docs/5.6/queries#selects
     */
    public function select($columns = ['*'])
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        $this->columns = [];
        $this->columns = $this->getUniqueColumns($columns);
        $this->columns = $columns;

        return $this;
    }

    /**
     * Additional option that should be appended to the 'SELECT' keyword. For example,
     * in MySQL, the option 'SQL_CALC_FOUND_ROWS' can be used.
     *
     * @param string $option
     * @return $this
     */
    public function selectOption($option)
    {
        $this->selectOption = $option;
        return $this;
    }

    /**
     * Add a subselect expression to the query.
     *
     * @param  \Closure|Query|string $query
     * @param  string  $as
     * @return Query|static
     *
     * @throws \InvalidArgumentException
     */
    public function selectSub($query, $as)
    {
        list($query, $bindings) = $this->createSub($query);

        return $this->selectRaw(
            '('.$query.') as '.$this->db->quoteColumnName($as), $bindings
        );
    }

    /**
     * Add a new "raw" select expression to the query.
     *
     * @param  string  $expression
     * @param  array   $bindings
     * @return Query|static
     */
    public function selectRaw($expression, array $bindings = [])
    {
        $this->addSelect(new Expression($expression));

        if ($bindings) {
            $this->addBinding($bindings, 'select');
        }

        return $this;
    }

    /**
     * Add a new select column to the query.
     *
     * @param  array|mixed  $columns
     * @return $this
     */
    public function addSelect($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        $columns = $this->getUniqueColumns($columns);

        $this->columns = array_merge((array) $this->columns, $columns);

        return $this;
    }

    /**
     * Force the query to only return distinct results.
     *
     * @return $this
     */
    public function distinct()
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * Creates a subquery and parse it.
     *
     * @param  \Closure|Query|string $query
     * @return array
     */
    protected function createSub($query)
    {
        // If the given query is a Closure, we will execute it while passing in a new
        // query instance to the Closure. This will give the developer a chance to
        // format and work with the query before we cast it to a raw SQL string.
        if ($query instanceof Closure) {
            $callback = $query;

            $callback($query = $this->forSubQuery());
        }

        return $this->parseSub($query);
    }

    /**
     * Parse the subquery into SQL and bindings.
     *
     * @param  mixed  $query
     * @return array
     */
    protected function parseSub($query)
    {
        if ($query instanceof self) {
            return [$query->toSql(), $query->getBindings()];

        } elseif (is_string($query)) {
            return [$query, []];

        } else {
            throw new InvalidArgumentException;
        }
    }

    /**
     * Returns unique column names excluding duplicates.
     * Columns to be removed:
     * - if column definition already present in SELECT part with same alias
     * - if column definition without alias already present in SELECT part without alias too
     * @param array $columns the columns to be merged to the select.
     *
     * @todo implement
     */
    protected function getUniqueColumns($columns)
    {
        return $columns;
    }


    /**
     * Set the table which the query is targeting.
     *
     * @param  string  $tables
     * @return $this
     */
    public function from($tables)
    {
        if (is_string($tables)) {
            $tables = preg_split('/\s*,\s*/', trim($tables), -1, PREG_SPLIT_NO_EMPTY);
        }

        $tables = is_array($tables) ? $tables : func_get_args();

        $this->from = $tables;

        return $this;
    }

    /**
     * Makes "from" fetch from a subquery.
     *
     * @param  \Closure|Query|string $query
     * @param  string  $as
     * @return Query|static
     *
     * @throws \InvalidArgumentException
     */
    public function fromSub($query, $as)
    {
        list($query, $bindings) = $this->createSub($query);

        return $this->fromRaw('('.$query.') as '.$this->db->quoteColumnName($as), $bindings);
    }

    /**
     * Add a raw from clause to the query.
     *
     * @param  string  $expression
     * @param  mixed   $bindings
     * @return Query|static
     */
    public function fromRaw($expression, $bindings = [])
    {
        $this->from = new Expression($expression);

        $this->addBinding($bindings, 'from');

        return $this;
    }

    /**
     * Add a join clause to the query.
     *
     * Example:
     * ```php
     * (new Query())->from('users')
     *  ->join('contacts', 'users.id', '=', 'contacts.user_id')
     *  ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
     *  ->select('users.*', 'contacts.phone', 'orders.price')
     *  ->all();
     * ```
     *
     * @param  string  $table
     * @param  string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @param  string  $type
     * @param  bool    $where
     * @return $this
     *
     * @see https://laravel.com/docs/5.6/queries#joins
     */
    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        $join = new JoinClause($this, $type, $table);

        // If the first "column" of the join is really a Closure instance the developer
        // is trying to build a join with a complex "on" clause containing more than
        // one condition, so we'll add the join and call a Closure with the query.
        if ($first instanceof Closure) {
            call_user_func($first, $join);

            $this->joins[] = $join;

            $this->addBinding($join->getBindings(), 'join');
        }

        // If the column is simply a string, we can assume the join simply has a basic
        // "on" clause with a single condition. So we will just build the join with
        // this simple join clauses attached to it. There is not a join callback.
        else {
            $method = $where ? 'where' : 'on';

            $this->joins[] = $join->$method($first, $operator, $second);

            $this->addBinding($join->getBindings(), 'join');
        }

        return $this;
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  string  $operator
     * @param  string  $second
     * @param  string  $type
     * @return Query|static
     */
    public function joinWhere($table, $first, $operator, $second, $type = 'inner')
    {
        return $this->join($table, $first, $operator, $second, $type, true);
    }

    /**
     * Add a subquery join clause to the query.
     *
     * @param  \Closure|Query|string $query
     * @param  string  $as
     * @param  string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @param  string  $type
     * @param  bool    $where
     * @return Query|static
     *
     * @throws \InvalidArgumentException
     */
    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        list($query, $bindings) = $this->createSub($query);

        $expression = '('.$query.') as '.$this->grammar->wrap($as);

        $this->addBinding($bindings, 'join');

        return $this->join(new Expression($expression), $first, $operator, $second, $type, $where);
    }

    /**
     * Add a left join to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @return Query|static
     */
    public function leftJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  string  $operator
     * @param  string  $second
     * @return Query|static
     */
    public function leftJoinWhere($table, $first, $operator, $second)
    {
        return $this->joinWhere($table, $first, $operator, $second, 'left');
    }

    /**
     * Add a subquery left join to the query.
     *
     * @param  \Closure|Query|string $query
     * @param  string  $as
     * @param  string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @return Query|static
     */
    public function leftJoinSub($query, $as, $first, $operator = null, $second = null)
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left');
    }

    /**
     * Add a right join to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @return Query|static
     */
    public function rightJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a "right join where" clause to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  string  $operator
     * @param  string  $second
     * @return Query|static
     */
    public function rightJoinWhere($table, $first, $operator, $second)
    {
        return $this->joinWhere($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a subquery right join to the query.
     *
     * @param  \Closure|Query|string $query
     * @param  string  $as
     * @param  string  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @return Query|static
     */
    public function rightJoinSub($query, $as, $first, $operator = null, $second = null)
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right');
    }

    /**
     * Add a "cross join" clause to the query.
     *
     * @param  string  $table
     * @param  string|null  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @return Query|static
     */
    public function crossJoin($table, $first = null, $operator = null, $second = null)
    {
        if ($first) {
            return $this->join($table, $first, $operator, $second, 'cross');
        }

        $this->joins[] = new JoinClause($this, 'cross', $table);

        return $this;
    }


    /**
     * Add a basic where clause to the query.
     *
     * @param  string|array|\Closure  $column
     * @param  mixed   $operator
     * @param  mixed   $value
     * @param  string  $boolean
     * @return $this
     *
     * @see https://laravel.com/docs/5.6/queries#where-clauses
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        list($value, $operator) = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            list($value, $operator) = [$operator, '='];
        }

        // If the value is a Closure, it means the developer is performing an entire
        // sub-select within the query and we will need to compile the sub-select
        // within the where clause to get the appropriate query record results.
        if ($value instanceof Closure) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        // If the value is "null", we will just assume the developer wants to add a
        // where null clause to the query. So, we will allow a short-cut here to
        // that method for convenience so the developer doesn't have to check.
        if (is_null($value)) {
            return $this->whereNull($column, $boolean, $operator !== '=');
        }

        // If the column is making a JSON reference we'll check to see if the value
        // is a boolean. If it is, we'll add the raw boolean string as an actual
        // value to the query to ensure this is properly handled by the query.
        //if (Str::contains($column, '->') && is_bool($value)) {
        if(mb_strpos($column, '->') !== false && is_bool($value)) {
            $value = new Expression($value ? 'true' : 'false');
        }

        // Now that we are working with just a simple query we can put the elements
        // in our array and add the query binding to our array of bindings that
        // will be bound to each SQL statements when it is finally executed.
        $type = 'Basic';

        $this->wheres[] = compact(
            'type', 'column', 'operator', 'value', 'boolean'
        );

        if (!$value instanceof Expression) {
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    /**
     * Add an array of where clauses to the query.
     *
     * @param  array  $column
     * @param  string  $boolean
     * @param  string  $method
     * @return $this
     */
    protected function addArrayOfWheres($column, $boolean, $method = 'where')
    {
        return $this->whereNested(function ($query) use ($column, $method, $boolean) {
            foreach ($column as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $query->{$method}(...array_values($value));

                } else {
                    $query->$method($key, '=', $value, $boolean);
                }
            }
        }, $boolean);
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param  string|array|\Closure  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return Query|static
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        list($value, $operator) = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a "where" clause comparing two columns to the query.
     *
     * @param  string|array  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @param  string|null  $boolean
     * @return Query|static
     */
    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and')
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($first)) {
            return $this->addArrayOfWheres($first, $boolean, 'whereColumn');
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            list($second, $operator) = [$operator, '='];
        }

        // Finally, we will add this where clause into this array of clauses that we
        // are building for the query. All of them will be compiled via a grammar
        // once the query is about to be executed and run against the database.
        $type = 'Column';

        $this->wheres[] = compact(
            'type', 'first', 'operator', 'second', 'boolean'
        );

        return $this;
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param  \Closure $callback
     * @param  string   $boolean
     * @return Query
     */
    public function whereNested(Closure $callback, $boolean = 'and')
    {
        call_user_func($callback, $query = $this->forNestedWhere());

        return $this->addNestedWhereQuery($query, $boolean);
    }

    /**
     * Create a new query instance for nested where condition.
     *
     * @return Query
     */
    public function forNestedWhere()
    {
        return $this->newQuery()->from($this->from);
    }

    /**
     * Add a full sub-select to the query.
     *
     * @param  string   $column
     * @param  string   $operator
     * @param  \Closure $callback
     * @param  string   $boolean
     * @return $this
     */
    protected function whereSub($column, $operator, Closure $callback, $boolean)
    {
        $type = 'Sub';

        // Once we have the query instance we can simply execute it so it can add all
        // of the sub-select's conditions to itself, and then we can cache it off
        // in the array of where clauses for the "main" parent query instance.
        call_user_func($callback, $query = $this->forSubQuery());

        $this->wheres[] = compact(
            'type', 'column', 'operator', 'query', 'boolean'
        );

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add an "or where" clause comparing two columns to the query.
     *
     * @param  string|array  $first
     * @param  string|null  $operator
     * @param  string|null  $second
     * @return Query|static
     */
    public function orWhereColumn($first, $operator = null, $second = null)
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    /**
     * Add a raw where clause to the query.
     *
     * @param  string  $sql
     * @param  mixed   $bindings
     * @param  string  $boolean
     * @return $this
     */
    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        $this->addBinding((array) $bindings, 'where');

        return $this;
    }

    /**
     * Add a raw or where clause to the query.
     *
     * @param  string  $sql
     * @param  mixed   $bindings
     * @return Query|static
     */
    public function orWhereRaw($sql, $bindings = [])
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a "where in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @param  string  $boolean
     * @param  bool    $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

//        if ($values instanceof EloquentBuilder) {
//            $values = $values->getQuery();
//        }

        // If the value is a query builder instance we will assume the developer wants to
        // look for any values that exists within this given query. So we will add the
        // query accordingly so that this query is properly executed when it is run.
        if ($values instanceof self) {
            return $this->whereInExistingQuery(
                $column, $values, $boolean, $not
            );
        }

        // If the value of the where in clause is actually a Closure, we will assume that
        // the developer is using a full sub-select for this "in" statement, and will
        // execute those Closures, then we can re-construct the entire sub-selects.
        if ($values instanceof Closure) {
            return $this->whereInSub($column, $values, $boolean, $not);
        }

        // Next, if the value is Arrayable we need to cast it to its raw array form so we
        // have the underlying array value instead of an Arrayable object which is not
        // able to be added as a binding, etc. We will then add to the wheres array.
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        // Finally we'll add a binding for each values unless that value is an expression
        // in which case we will just skip over it since it will be the query as a raw
        // string and not as a parameterized place-holder to be replaced by the PDO.
        foreach ($values as $value) {
            if (! $value instanceof Expression) {
                $this->addBinding($value, 'where');
            }
        }

        return $this;
    }

    /**
     * Add an "or where in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @return Query|static
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @param  string  $boolean
     * @return Query|static
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @return Query|static
     */
    public function orWhereNotIn($column, $values)
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * Add a where in with a sub-select to the query.
     *
     * @param  string   $column
     * @param  \Closure $callback
     * @param  string   $boolean
     * @param  bool     $not
     * @return $this
     */
    protected function whereInSub($column, Closure $callback, $boolean, $not)
    {
        $type = $not ? 'NotInSub' : 'InSub';

        // To create the exists sub-select, we will actually create a query and call the
        // provided callback with the query so the developer may set any of the query
        // conditions they want for the in clause, then we'll put it in this array.
        call_user_func($callback, $query = $this->forSubQuery());

        $this->wheres[] = compact('type', 'column', 'query', 'boolean');

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add an external sub-select to the query.
     *
     * @param  string   $column
     * @param  Query|static  $query
     * @param  string   $boolean
     * @param  bool     $not
     * @return $this
     */
    protected function whereInExistingQuery($column, $query, $boolean, $not)
    {
        $type = $not ? 'NotInSub' : 'InSub';

        $this->wheres[] = compact('type', 'column', 'query', 'boolean');

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param  string  $column
     * @param  string  $boolean
     * @param  bool    $not
     * @return $this
     */
    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';

        $this->wheres[] = compact('type', 'column', 'boolean');

        return $this;
    }

    /**
     * Add an "or where null" clause to the query.
     *
     * @param  string  $column
     * @return Query|static
     */
    public function orWhereNull($column)
    {
        return $this->whereNull($column, 'or');
    }

    /**
     * Add a "where not null" clause to the query.
     *
     * @param  string  $column
     * @param  string  $boolean
     * @return Query|static
     */
    public function whereNotNull($column, $boolean = 'and')
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add a where between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        $this->wheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        $this->addBinding($this->cleanBindings($values), 'where');

        return $this;
    }

    /**
     * Add an or where between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @return Query|static
     */
    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * Add a where not between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @param  string  $boolean
     * @return Query|static
     */
    public function whereNotBetween($column, array $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add an or where not between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @return Query|static
     */
    public function orWhereNotBetween($column, array $values)
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    /**
     * Add an "or where not null" clause to the query.
     *
     * @param string $column
     * @return Query|static
     */
    public function orWhereNotNull($column)
    {
        return $this->whereNotNull($column, 'or');
    }

    /**
     * Add a "group by" clause to the query.
     *
     * @param  array  ...$groups
     * @return $this
     *
     * @see https://laravel.com/docs/5.6/queries#ordering-grouping-limit-and-offset
     */
    public function groupBy(...$groups)
    {
        foreach ($groups as $group) {
            if (is_null($group)) $group = [];
            $group = is_array($group) ? $group : [$group];

            $this->groups = array_merge((array) $this->groups, $group);
        }

        return $this;
    }

    /**
     * Add a "having" clause to the query.
     *
     * Example:
     * ```php
     * (new Query())->from('users')
     *   ->groupBy('first_name', 'status')
     *   ->having('account_id', '>', 100)
     *   ->all();
     * ```
     *
     * @param  string  $column
     * @param  string|null  $operator
     * @param  string|null  $value
     * @param  string  $boolean
     * @return $this
     *
     * @see https://laravel.com/docs/5.6/queries#ordering-grouping-limit-and-offset
     */
    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        $type = 'Basic';

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        list($value, $operator) = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            list($value, $operator) = [$operator, '='];
        }

        $this->havings[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (! $value instanceof Expression) {
            $this->addBinding($value, 'having');
        }

        return $this;
    }

    /**
     * Add a "or having" clause to the query.
     *
     * @param  string  $column
     * @param  string|null  $operator
     * @param  string|null  $value
     * @return Query|static
     */
    public function orHaving($column, $operator = null, $value = null)
    {
        list($value, $operator) = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->having($column, $operator, $value, 'or');
    }

    /**
     * Add a raw having clause to the query.
     *
     * @param  string  $sql
     * @param  array   $bindings
     * @param  string  $boolean
     * @return $this
     */
    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        $type = 'Raw';

        $this->havings[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'having');

        return $this;
    }

    /**
     * Add a raw or having clause to the query.
     *
     * @param  string  $sql
     * @param  array   $bindings
     * @return Query|static
     */
    public function orHavingRaw($sql, array $bindings = [])
    {
        return $this->havingRaw($sql, $bindings, 'or');
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param  string  $column
     * @param  string  $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'ASC')
    {
        $property = $this->unions ? 'unionOrders' : 'orders';
        $this->{$property}[] = [
            'column' => $column,
            'direction' => strtoupper($direction) == 'ASC' ? 'ASC' : 'DESC',
        ];

        return $this;
    }

    /**
     * Add a descending "order by" clause to the query.
     *
     * @param  string  $column
     * @return $this
     */
    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Put the query's results in random order.
     *
     * @param  string  $seed
     * @return $this
     */
    public function inRandomOrder($seed = '')
    {
        return $this->orderByRaw($this->builder->buildRandom($seed));
    }

    /**
     * Add a raw "order by" clause to the query.
     *
     * @param  string  $sql
     * @param  array  $bindings
     * @return $this
     */
    public function orderByRaw($sql, $bindings = [])
    {
        $type = 'Raw';

        $property = $this->unions ? 'unionOrders' : 'orders';
        $this->{$property}[] = compact('type', 'sql');

        $this->addBinding($bindings, 'order');

        return $this;
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param  int  $value
     * @return $this
     */
    public function limit($value)
    {
        $value = (int)$value;
        if ($value >= 0) {
            $property = $this->unions ? 'unionLimit' : 'limit';
            $this->{$property} = $value;
        }

        return $this;
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param  int  $value
     * @return $this
     */
    public function offset($value)
    {
        $property = $this->unions ? 'unionOffset' : 'offset';

        $this->$property = max(0, $value);

        return $this;
    }

    /**
     * Add a union statement to the query.
     *
     * @param  Query|Closure  $query
     * @param  bool  $all
     * @return Query|static
     */
    public function union($query, $all = false)
    {
        if ($query instanceof Closure) {
            call_user_func($query, $query = $this->newQuery());
        }

        $this->unions[] = compact('query', 'all');

        $this->addBinding($query->getBindings(), 'union');

        return $this;
    }

    /**
     * Add a union all statement to the query.
     *
     * @param  Query|Closure  $query
     * @return Query|static
     */
    public function unionAll($query)
    {
        return $this->union($query, true);
    }

    /**
     * Column by which the query results should be indexed by
     * @param string|callable $column

     * @return $this
     */
    public function indexBy($column)
    {
        $this->indexBy = $column;

        return $this;
    }

    // ===== Service methods =====

    /**
     * Get a new instance of the query builder.
     *
     * @return $this
     */
    public function newQuery()
    {
        return new static($this->db);
    }

    /**
     * Create a new query instance for a sub-query.
     *
     * @return $this
     */
    protected function forSubQuery()
    {
        return $this->newQuery();
    }

    /**
     * Add another query builder as a nested where to the query builder.
     *
     * @param  Query|static $query
     * @param  string  $boolean
     * @return $this
     */
    public function addNestedWhereQuery($query, $boolean = 'and')
    {
        if (count($query->wheres)) {
            $type = 'Nested';

            $this->wheres[] = compact('type', 'query', 'boolean');

            $this->addBinding($query->getRawBindings()['where'], 'where');
        }

        return $this;
    }

    /**
     * Returns table names used in [[from]] indexed by aliases.
     *
     * @return string[] table names indexed by aliases
     * @throws \Exception
     */
    public function getTablesUsedInFrom()
    {
        if (empty($this->from)) {
            return [];
        }

        if (is_array($this->from)) {
            $tableNames = $this->from;

        } elseif (is_string($this->from)) {
            $tableNames = preg_split('/\s*,\s*/', trim($this->from), -1, PREG_SPLIT_NO_EMPTY);

        } elseif ($this->from instanceof Expression) {
            $tableNames = [$this->from];

        } else {
            throw new \Exception(gettype($this->from) . ' in $from is not supported.');
        }

        return $this->cleanUpTableNames($tableNames);
    }

    /**
     * Prepare the value and operator for a where clause.
     *
     * @param  string  $value
     * @param  string  $operator
     * @param  bool  $useDefault
     * @return array
     *
     * @throws InvalidArgumentException
     *
     * @todo move to builder so Adapter could use this method too
     */
    public function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, '='];

        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * Determine if the given operator is supported.
     *
     * @param  string  $operator
     * @return bool
     */
    protected function invalidOperator($operator)
    {
        return ! in_array(strtolower($operator), $this->operators, true) &&
            ! in_array(strtolower($operator), $this->grammar->getOperators(), true);
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * Prevents using Null values with invalid operators.
     *
     * @param  string  $operator
     * @param  mixed  $value
     * @return bool
     */
    protected function invalidOperatorAndValue($operator, $value)
    {
        return is_null($value) && in_array($operator, $this->operators) &&
            !in_array($operator, ['=', '<>', '!=']);
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        return $this->builder->build($this);
    }



    // ==== Query Methods ====

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @param bool $all get all records or just single row
     *
     * @return array
     * @throws \Exception
     */
//    protected function runSelect($all = true)
//    {
//        $sql = $this->toSql();
//        if(empty($sql)) return null;
//
//        $params = $this->getBindings();
//
//        if($all) {
//            $ret = $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
//
//        } else {
//            $ret = $this->db->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
//        }
//
//        return $ret;
//    }

    /**
     * Prepare Query to adapter
     * @return null|\PDOStatement @return \PDOStatement
     * @throws \Exception
     */
    protected function prepareQuery()
    {
        $sql = $this->toSql();
        if(empty($sql)) return null;

        $params = $this->getBindings();

        return $this->db->query($sql, $params);
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @return array|null the query results. If the query results in nothing, an empty array will be returned.
     */
    public function all()
    {
        $statement = $this->prepareQuery();
        if(empty($statement)) return null;

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $this->populate($rows);
    }

    /**
     * Executes the query and returns a single row of result.
     *
     * @return array
     */
    public function one()
    {
        $result = $this->limit(1)->all();
        if(empty($result)) return null;

        return array_shift($result);
    }

    /**
     * Executes the query and returns the first column of the result.
     *
     * @param null $name
     * @return array
     */
    public function column($name = null)
    {
        $tables = $this->getTablesUsedInFrom();
        $table = !empty($tables) ? key($tables).'.' : '';

        if(!empty($name)) {
            if (strpos($name, '.') === false) {
                $this->columns = [$table . $name];

            } else {
                $this->columns = [$name];
            }
        }

        if ($this->indexBy === null) {
            $statement = $this->prepareQuery();
            if(empty($statement)) return null;

            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        }

        if (is_string($this->indexBy) && is_array($this->columns) && count($this->columns) === 1) {
            if (strpos($this->indexBy, '.') === false) {
                $this->columns[] = $table.$this->indexBy;

            } else {
                $this->columns[] = $this->indexBy;
            }
        }

        $statement = $this->prepareQuery();
        if(empty($statement)) return null;

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $results = [];
        foreach ($rows as $row) {
            $value = reset($row);

            if ($this->indexBy instanceof \Closure) {
                $results[call_user_func($this->indexBy, $row)] = $value;

            } else {
                $results[$row[$this->indexBy]] = $value;
            }
        }

        return $results;
    }

    /**
     * Get a single column's value from the first result of a query.
     *
     * @param null $column
     * @return string|null|false
     */
    public function value($column = null)
    {
        if(!empty($column)) {
            if (strpos($column, '.') === false) {
                $tables = $this->getTablesUsedInFrom();
                $table = !empty($tables) ? key($tables).'.' : '';

                $this->columns = [$table . $column];

            } else {
                $this->columns = [$column];
            }
        }

        $statement = $this->prepareQuery();
        if(empty($statement)) return null;

        return $statement->fetchColumn();
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists()
    {
        $sql = $this->builder->buildExists($this);

        $result = $this->db->query($sql, $this->getBindings())->fetch();

        // If the results has rows, we will get the row and see if the exists column is a
        // boolean true. If there is no results for this query we will return false as
        // there are no rows for this query at all and we can return that info here.
        if (isset($result[0])) {
            $results = (array) $result[0];

            return (bool)$result['exists'];
        }

        return null;
    }

    /**
     * Determine if no rows exist for the current query.
     *
     * @return bool
     */
    public function notExists()
    {
        return !$this->exists();
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param string $expression the COUNT expression. Defaults to '*'.
     * @return int
     */
    public function count($expression = '*')
    {
        $expr = [];
        if (!is_null($expression)) {
            $expr = !is_array($expression) ? [$expression] : $expression;
        }

        return (int) $this->aggregate(__FUNCTION__, $expr);
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function min($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the sum of the values of a given column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function sum($column)
    {
        $result = $this->aggregate(__FUNCTION__, [$column]);

        return $result ?: 0;
    }

    /**
     * Retrieve the average of the values of a given column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function avg($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Alias for the "avg" method.
     *
     * @param  string  $column
     * @return mixed
     */
    public function average($column)
    {
        return $this->avg($column);
    }

    /**
     * Execute an aggregate function on the database.
     *
     * @param  string  $function
     * @param  array   $columns
     * @return mixed
     */
    public function aggregate($function, $columns = ['*'])
    {
        $newQuery = clone $this;
        $newQuery->columns = $columns;
        $newQuery->bindings['select'] = [];

        $results = $newQuery->setAggregate($function, $columns)->all();

        if (!empty($results)) {
            return array_change_key_case((array) $results[0])['aggregate'];
        }
    }

    /**
     * Set the aggregate property without running the query.
     *
     * @param  string  $function
     * @param  array  $columns
     * @return $this
     */
    protected function setAggregate($function, $columns)
    {
        $this->aggregate = compact('function', 'columns');

        if (empty($this->groups)) {
            $this->orders = null;

            $this->bindings['order'] = [];
        }

        return $this;
    }

    /**
     * Converts the raw query results into the format as specified by this query.
     * This method is internally used to convert the data fetched from database
     * into the format as required by this query.
     * @param array $rows the raw query result from database
     * @return array the converted query result
     */
    public function populate($rows)
    {
        if(empty($rows)) return null;

        if ($this->indexBy === null) return $rows;

        $result = [];
        $index = $this->indexBy;
        foreach ($rows as $row) {
            $result[$row[$index]] = $row;
        }

        return $result;
    }
    // ==== /Query Methods ====


    /**
     * Prepares for building SQL.
     * This method is called by [[Query\Builder]] when it starts to build SQL from a query object.
     * You may override this method to do some final preparation work when converting a query into a SQL statement.
     * @param Query\Builder $builder
     *
     * @return $this a prepared query instance which will be used by [[QueryBuilder]] to build the SQL
     */
    public function prepare($builder)
    {
        return $this;
    }

    /**
     * Clean up table names and aliases
     * Both aliases and names are enclosed into {{ and }}.
     * @param array $tableNames non-empty array
     * @return string[] table names indexed by aliases
     * @throws \Exception
     */
    protected function cleanUpTableNames($tableNames)
    {
        $cleanedUpTableNames = [];
        foreach ($tableNames as $alias => $tableName) {
            if (is_string($tableName) && !is_string($alias)) {
                $pattern = <<<PATTERN
~
^
\s*
(
(?:['"`\[]|{{)
.*?
(?:['"`\]]|}})
|
\(.*?\)
|
.*?
)
(?:
(?:
    \s+
    (?:as)?
    \s*
)
(
   (?:['"`\[]|{{)
    .*?
    (?:['"`\]]|}})
    |
    .*?
)
)?
\s*
$
~iux
PATTERN;
                if (preg_match($pattern, $tableName, $matches)) {
                    if (isset($matches[2])) {
                        list(, $tableName, $alias) = $matches;
                    } else {
                        $tableName = $alias = $matches[1];
                    }
                }
            }


            if ($tableName instanceof Expression) {
                if (!is_string($alias)) {
                    throw new \Exception('To use Expression in from() method, pass it in array format with alias.');
                }
                $cleanedUpTableNames[$alias] = $tableName;

            } elseif ($tableName instanceof self) {
                $cleanedUpTableNames[$alias] = $tableName;

            } else {
                //$cleanedUpTableNames[$alias] = $this->ensureNameQuoted($tableName);
                $cleanedUpTableNames[$alias] = $tableName;
            }
        }

        return $cleanedUpTableNames;
    }

    /**
     * Set the bindings on the query builder.
     *
     * @param  array   $bindings
     * @param  string  $type
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function setBindings(array $bindings, $type = 'where')
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        $this->bindings[$type] = $bindings;

        return $this;
    }

    /**
     * Add a binding to the query.
     *
     * @param  mixed   $value
     * @param  string  $type
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function addBinding($value, $type = 'where')
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_merge($this->bindings[$type], $value));

        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }

    /**
     * Get the current query value bindings in a flattened array.
     *
     * @return array
     *
     * @todo избавиться от flatten
     */
    public function getBindings()
    {
//        var_dump_($this->bindings);

        $ret = static::flatten($this->bindings);

//        var_dump_($ret);
//
//        var_dump__($ret);

        return $ret;
    }

    /**
     * Get the raw array of bindings.
     *
     * @return array
     */
    public function getRawBindings()
    {
        return $this->bindings;
    }

    /**
     * Remove all of the expressions from a list of bindings.
     *
     * @param  array  $bindings
     * @return array
     */
    protected function cleanBindings(array $bindings)
    {
        return array_values(array_filter($bindings, function ($binding) {
            return ! $binding instanceof Expression;
        }));
    }

    /// ====
    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param  array  $array
     * @param  int  $depth
     * @return array
     */
    public static function flatten($array, $depth = INF)
    {
        $result = [];

        foreach ($array as $item) {
            if (! is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, static::flatten($item, $depth - 1));
            }
        }

        return $result;
    }

}