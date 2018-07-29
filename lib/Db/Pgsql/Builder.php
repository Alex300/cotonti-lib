<?php

namespace lib\Db\Pgsql;

defined('COT_CODE') or die('Wrong URL.');

use lib\Db\Query;

/**
 * Query Builder builds a SELECT SQL statement based on the specification given as a [[Query]] object.
 */
class Builder extends \lib\Db\Query\Builder
{
    /**
     * All of the available clause operators.
     *
     * @var array
     */
    protected $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'between', 'ilike', 'not ilike',
        '~', '&', '|', '#', '<<', '>>', '<<=', '>>=',
        '&&', '@>', '<@', '?', '?|', '?&', '||', '-', '-', '#-',
        'is distinct from', 'is not distinct from',
    ];

    /**
     * Compile a select query into SQL.
     *
     * @param  Query  $query
     * @return string
     */
    public function build(Query $query, $params = [])
    {
        $sql = parent::build($query);

        if ($query->unions) {
            $sql = '('.$sql.') '.$this->buildUnions($query);
        }

        return $sql;
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

        return $conjunction.'('.$union['query']->toSql().')';
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param  string  $seed
     * @return string
     */
    public function buildRandom($seed)
    {
        return 'RAND('.$seed.')';
    }
}