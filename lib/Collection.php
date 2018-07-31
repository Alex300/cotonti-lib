<?php

namespace lib;

defined('COT_CODE') or die('Wrong URL.');

/**
 * Collection
 *
 * @author Kalnov Alexey http://portal30.ru
 */
class Collection extends \ArrayObject implements \Countable
{
    /**
     * @var int|null Можно использовать для храннеия общего количества записей, соотвествующих выборке
     */
    protected $totalCount = 0;

    function __construct($array = [], $totalCount = null)
    {
        //parent::__construct($array,ArrayObject::ARRAY_AS_PROPS);
        parent::__construct($array);

        if(is_null($totalCount)) $totalCount = $this->count();

        $this->totalCount = $totalCount;
    }

    public function totalCount()
    {
        return $this->totalCount;
    }

    public function setTotalCount($num)
    {
        $this->totalCount = $num;
    }

    public function keys()
    {
        return array_keys($this->getArrayCopy());
    }
}