<?php

/**
 *
 * Mongo Mapper
 *
 * @author Mankov
 */
class Som_Model_Mapper_Mongo extends Som_Model_Mapper_Abstract implements Som_Model_Mapper_Interface {

    protected $_adapter = null;
    protected $_dbinfo = null;

    function __construct($dbinfo) {
        $this->_dbinfo = $dbinfo;

        $conn           = new Mongo();
        $name_db        = isset($dbinfo['dbname'])?$dbinfo['dbname']:SHOP_SHORTNAME;
        $this->_adapter = $conn->$name_db;
    }

    protected function _getCollection() {
        $collectionName = $this->_dbinfo['tbname'];
        return $this->_adapter->$collectionName;
    }

    /**
     * @access public
     *
     * @param Shp_Model_Abstract $model
     */
    public function create(Som_Model_Abstract $model) {
        $model = $this->_checkcreate($model);

        $this
            ->_getCollection()
            ->insert($model->_data);
    }

    /**
     * @access public
     *
     * @param Plum_Model $model
     */
    public final function update(Som_Model_Abstract $model) {
        $model = $this->_checkupdate($model);

        $this
            ->_getCollection()
            ->save($model->_data);
    }

    /**
     * @access public
     *
     * @param Plum_Model $model
     */
    public function delete(Som_Model_Abstract $model) {
        $model = $this->_checkdelete($model);
        $id = $model->getId();
        $id = is_a('MongoId', $id) ? $id : new MongoId($id);
        $this->_getCollection()->remove(array('_id' => $id));
    }


    function getId(Som_Model_Abstract $model) {
        return (is_a($model->_data['_id'], 'MongoId')) ? (string)$model->_data['_id'] : $model->_data['_id'];
    }

    protected function toMongoId($v) {
        if (is_a($v, "MongoId")) return $v;

        if (is_array($v)) {
            $m_new_id = [];
            foreach ($v as $id) $m_new_id[] = new MongoId($id);
            return $m_new_id;
        }

        return new MongoId($v);
    }


    protected function correctCond($cond) {
        $attributes = Som_Model_Mapper_Manager::getReflectionInfo($this->_dbinfo['class']);

        foreach ($attributes as $attr => $params) {
            if (isset($cond[$attr])) {
                if (is_array($cond[$attr])) {

                    // Поиск по вхождению
                    if (isset($cond[$attr]['$in'])) $cond["_link.$attr"] = array('$in' => $this->toMongoId($cond[$attr]['$in']));
                    else $cond["_link.$attr"] = array('$in' => $this->toMongoId($cond[$attr]));

                }
                elseif (is_a($cond[$attr], 'MongoId')) {
                    $cond["_link.$attr"] = $cond[$attr];
                }
                else {
                    $cond["_link.$attr"] = new MongoId($cond[$attr]);
                }
                unset($cond[$attr]);
            }
        }


        if (isset($cond['_id']) && !is_object($cond['_id'])) $cond['_id'] = new MongoId($cond['_id']);
        if (isset($cond['id'])) {
            if (!is_object($cond['id'])) $cond['_id'] = new MongoId($cond['id']);
            else
                $cond['_id'] = $cond['id'];

            unset($cond['id']);
        }

        return $cond;
    }

    /**
     * @access public
     *
     * @param  $cond
     * @param  $sort
     *
     * @return Shp_Model_Abstract
     *
     */
    public function fetchOne($cond, $sort = null) {
        $cond = $this->correctCond($cond);


        $collection = $this->_getCollection();

        $cursor = $collection->findOne($cond);
        if (!is_null($sort) && is_array($sort) && count($sort)) $cursor->sort($sort);

        if (is_null($cursor)) return false;
        $model_name = $this->_dbinfo['class'];
        $model      = new $model_name($cursor);

        return $model;
    }

    /**
     * @return null
     */
    public function getCollection() {
        return $this->_getCollection();
    }

    /**
     * @access public
     *
     * @param  array $cond
     * @param  array $sort
     * @param  int   $limit
     * @param  int   $offset
     */
    public function fetchAll($cond, $sort = null, $limit = null, $offset = null) {
        $cond = $this->correctCond($cond);
        $collection = $this->_getCollection();
        $cursor     = $collection->find($cond);

        if (!is_null($sort)) $cursor->sort($sort);
        if (!is_null($limit)) $cursor->limit($limit);
        if (!is_null($offset)) $cursor->skip($offset);

        $list = array();
        $class = $this->_dbinfo['class'];
        foreach ($cursor as $item) $list[] = new $class($item);

        return $list;
    }

    /**
     * @access public
     *
     * @param  array $cond
     * @param  array $sort
     * @param  int   $limit
     * @param  int   $offset
     */
    public function fetchAllByRelation($cond, $sort = null, $limit = null, $offset = null) {
        $collection = $this->_getCollection();

        if (is_a($cond, 'Som_Model_Abstract')) {
            // ищем связь
            $className = get_class($cond);

            $info     = Som_Model_Mapper_Manager::getReflectionInfo($this->_dbinfo['class']);
            $fieldRel = false;
            foreach ($info as $field => $m) {
                if (array_search($className, $m)) $fieldRel = $field;
            }
            if (!$fieldRel) throw new Exception("Нет связи у объекта к объекту $className");

            $cursor = $collection->find(["_link.$fieldRel" => $cond->id]);

        }
        elseif (is_array($cond)) {
            $info      = Som_Model_Mapper_Manager::getReflectionInfo($this->_dbinfo['class']);

            $m_search  = array();
            foreach ($cond as $obj) {
                $className = get_class($obj);
                if (!is_a($obj, 'Som_Model_Abstract')) throw new Exception("Объект по которму делается филтрация не принадлежит Som_Model_Abstract");

                // ищем связь
                $fieldRel = false;
                foreach ($info as $field => $m) {

                    if (array_search($className, $m)) {
                        $fieldRel = $field;
                    }
                }
                if (!$fieldRel) throw new Exception("Нет связи у объекта к объекту $className");
                $m_search["_link.$fieldRel"] = new MongoId($obj->id);
            }

            if (count($m_search) == 0) throw new Exception("Нет условий поиска");

            $cursor = $collection->find($m_search);
        }
        else {
            throw new Exception("Не верно заданы условия поиска");
        }

        if (!is_null($sort)) $cursor->sort($sort);
        if (!is_null($limit)) $cursor->limit($limit);
        if (!is_null($offset)) $cursor->skip($offset);

        $list = array();
        $class = $this->_dbinfo['class'];
        foreach ($cursor as $item) $list[] = new $class($item);

        return $list;
    }

    /**
     * @access public
     *
     * @param string $cond
     *
     * @return int
     */
    public final function getCount($cond) {
        $cond = $this->correctCond($cond);

        $collection = $this->_getCollection();
        $cursor     = $collection->find($cond);
        return $cursor->count();
    }

}