<?php

namespace lib\Db;

defined('COT_CODE') or die('Wrong URL.');

/**
 * Class ActiveQuery
 *
 * ActiveQuery represents a DB query associated with an Active Record class.
 *
 * @method ActiveRecord[] all()
 * @method ActiveRecord   one()
 *
 * @package Db
 */
class ActiveQuery extends Query
{
    /**
     * The name of the ActiveRecord class.
     * @var string
     */
    protected $modelClass;

    /**
     * Whether to return each record as an array or as ActiveRecord instances
     *
     * @var bool
     */
    public $asArray = false;

    /**
     * Constructor.
     * @param string $modelClass the model class associated with this query
     * @param array $config configurations to be applied to the newly created query object
     * @throws \Exception
     */
    public function __construct($modelClass, $config = [])
    {
        $this->modelClass = $modelClass;

        $this->from = $modelClass::tableName();

        parent::__construct($modelClass::adapter(), $config);
    }

    public function prepare($builder)
    {
        $table = $this->modelClass::tableName();

        if (empty($this->from)) {
            $this->from = $table;
        }

        //if (empty($this->aggregate) && empty($this->columns) && !empty($this->joins)) {
        if (empty($this->aggregate) && empty($this->columns)) {
            $this->columns = ["$table.*"];
        }

        return $this;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return $this
     */
    public function newQuery()
    {
        return new static($this->modelClass);
    }

    /**
     * Create a new query instance for a sub-query.
     *
     * @return Query
     */
    protected function forSubQuery()
    {
        return new Query();
    }

    public function populate($rows)
    {
        if (empty($rows))  return null;

        if($this->asArray || !empty($this->aggregate)) return parent::populate($rows);

        /* @var $class ActiveRecord */
        $class = $this->modelClass;

        $models = [];
        $index = !empty($this->indexBy) ? $this->indexBy : $class::primaryKey();

        foreach ($rows as $row) {
            $model = new $class($row);
            $models[$row[$index]] = $model;
        }

        return $models;
    }
}