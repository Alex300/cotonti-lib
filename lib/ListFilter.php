<?php
/*
Пример конфигурации:

Класс предназначен для генерации элементов формы фильтров, и для построения условий выборки для SOM на основе этих фильтров.
Также он на основе активных фильтров генерирует URL параметры, например для постраничной навигации.

$filterConfig = array(
    'fields' => array(
        // Конфигурация общая для всех фильтров по данному полю
        'auto_state' => array(
            'data' => dictionary_model_Value::keyValPairs(array(array('dictionary', 9)))
        ),
        'year' => array(
            'element' => 'select', // 'text', 'select' - для элементов, где это допустимо
            'data' => range(50, 0),
            'type' => 'int',
        ),
    )
);

Конфигурацию любого поля можно "перекрыть" передав соотвествующие параметры при вызове метода рендера самого элемента:
Нарпример в скрипте вида:
<?=$filter->more('year', array('element' => 'select', 'no_matter' => 'от', 'placeholder' => 'от', 'class' => 'input-sm'))?>

*/

/**
 * Filters for SOM
 *
 * @author Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 * @package Cotonti Lib
 * @subpackage SOM
 */
class ListFilter
{
    /**
     * @var string symbol for GET parameter which transfer filter array
     */
    public $getParam = 'lf';

    /**
     * @var bool Загружать возможные варианты из БД, если они не переданы в параметре data в конфиге поля
     */
    public $loadVariants = false;

    /**
     * @var Som_Model_Abstract строка с названием класса модели
     */
    public $modelName = '';


    public $modelFields = Null;

    /**
     * @var array
     *
     */
    public $fields = array();

    /**
     * разрешенные поля
     * @var array
     */
    public $allowedFields = array();

    /**
     * Значения фильтров
     * @var array
     */
    protected $filters = array();

    /**
     * Флаг, указывающий на то что какой либо из фильтров активен
     * @var bool
     */
    public $active = false;

    /**
     * @var array активные фильтры
     */
    public $activeFilters = array();

    /**
     * @var string Отображение опции "Не имеет значения"
     */
    public $noMatter = null;

    /**
     *
     * @param mixed $config the configuration for this element.
     * @param mixed $parent the direct parent of this element.
     *
     * @see configure
     */
    public function __construct($config = null) {
        $this->noMatter = cot::$R['code_option_empty'];

        if(!empty($config)) $this->setConfig($config);

        $this->init();
    }

    public function init() { }

    public function setConfig($config) {
        if (is_string($config)) $config = require($config . '.php');

        if (is_array($config)) {
            foreach ($config as $name => $value) {
                $this->$name = $value;
            }
        }

        if(empty($this->allowedFields)) {
            $this->allowedFields = array_keys($this->fields);
        }
    }

    /**
     * Формирует условие выборки для SOM
     * @return array
     */
    public function condition() {
        $cond = array();

        $filtersArr = cot_import($this->getParam, 'G', 'ARR');
        if(empty($filtersArr)) return $cond;

        foreach($filtersArr as $field => $filter) {
            // Используем только разрешенные поля
            if(!in_array($field, $this->allowedFields)) {
                unset($filtersArr[$field]);
                continue;
            }
            $fieldType = $this->fieldType($field);
            if(empty($fieldType)) $fieldType = 'text';    // По умолчанию

            if(isset($filtersArr[$field]['checklistbox'])) {
                if (is_array($filtersArr[$field]['checklistbox']) && !empty($filtersArr[$field]['checklistbox'])) {
                    $fData = array();
                    $tmp = $this->genCondition($field, $filtersArr[$field]['checklistbox'], 'array');
                    if (!empty($tmp)) $cond[] = $tmp;
                }else {
                    unset($filtersArr[$field]['checklistbox']);
                }
            }

            if(isset($filtersArr[$field]['is'])) {
                if ($filtersArr[$field]['is'] != '' && !is_null($filtersArr[$field]['is'])) {
                    $filtersArr[$field]['is'] = $this->importValue($filtersArr[$field]['is'], $fieldType);
                    $tmp = $this->genCondition($field, $filtersArr[$field]['is'], 'is');
                    if (!empty($tmp)) $cond[] = $tmp;
                }else {
                    unset($filtersArr[$field]['is']);
                }
            }

            if(isset($filtersArr[$field]['like'])) {
                if ($filtersArr[$field]['like'] != '' && !is_null($filtersArr[$field]['like'])) {
                    $filtersArr[$field]['like'] = $this->importValue($filtersArr[$field]['like'], $fieldType);
                    $tmp = $this->genCondition($field, $filtersArr[$field]['like'], 'like');
                    if (!empty($tmp)) $cond[] = $tmp;
                }else {
                    unset($filtersArr[$field]['like']);
                }
            }


            if(isset($filtersArr[$field]['more'])) {
                if($fieldType == 'text') $fieldType = 'double'; // Только числовой тип
                if ($filtersArr[$field]['more'] != '' && !is_null($filtersArr[$field]['more'])) {
                    $filtersArr[$field]['more'] = $this->importValue($filtersArr[$field]['more'], $fieldType);
                    $tmp = $this->genCondition($field, $filtersArr[$field]['more'], 'more');
                    if (!empty($tmp)) $cond[] = $tmp;
                } else {
                    unset($filtersArr[$field]['more']);
                }
            }

            if(isset($filtersArr[$field]['less'])) {
                if($fieldType == 'text') $fieldType = 'double'; // Только числовой тип
                if ($filtersArr[$field]['less'] != '' && !is_null($filtersArr[$field]['less'])) {
                    $filtersArr[$field]['less'] = $this->importValue($filtersArr[$field]['less'], $fieldType);
                    $tmp = $this->genCondition($field, $filtersArr[$field]['less'], 'less');
                    if (!empty($tmp)) $cond[] = $tmp;
                }else {
                    unset($filtersArr[$field]['less']);
                }
            }

            if(isset($filtersArr[$field]['radio'])) {
                if ($filtersArr[$field]['radio'] != '' && !is_null($filtersArr[$field]['radio'])) {
                    $fData = array();
                    $tmp = $this->genCondition($field, $filtersArr[$field]['radio'], 'is');
                    if (!empty($tmp)) $cond[] = $tmp;
                }else {
                    unset($filtersArr[$field]['radio']);
                }
            }

            if(isset($filtersArr[$field]['radio'])) {
                if ($filtersArr[$field]['radio'] != '' && !is_null($filtersArr[$field]['radio'])) {
                    $fData = array();
                    $tmp = $this->genCondition($field, $filtersArr[$field]['radio'], 'is');
                    if (!empty($tmp)) $cond[] = $tmp;
                }else {
                    unset($filtersArr[$field]['radio']);
                }
            }


        }

        $this->filters = $filtersArr;

        return $cond;
    }

    protected function genCondition($field, $val, $fType) {

        if($val == 'nullval') return null;

        switch ($fType) {
            case 'more':
                $this->active = true;
                $this->activeFilters[] = $field;
                return array($field, $val, '>=');
                break;

            case 'less':
                $this->active = true;
                $this->activeFilters[] = $field;
                return array($field, $val, '<=');
                break;

            case 'array':
                $this->active = true;
                $this->activeFilters[] = $field;
                return array($field, $val);
                break;

            case 'like':
                $this->active = true;
                $this->activeFilters[] = $field;
                return array($field, '*'.$val.'*');
                break;

            case 'is':
            default:
                $this->active = true;
                $this->activeFilters[] = $field;
                return array($field, $val);
                break;
        }

        return null;
    }

    /**
     * Активен ли фильтр для заданного поля
     * @param $field
     * @return bool
     */
    public function isActive($field) {
        return in_array($field, $this->activeFilters);
    }

    /**
     * Параметры для генерации URL'а, например для пагинации
     * @return array
     */
    public function urlParams() {
        $params = array();

        if(empty($this->filters)) return $params;

        foreach($this->filters as $field => $filters) {
            if(!empty($filters) && is_array($filters)) {
                foreach($filters as $type => $val) {
                    if(in_array($val, array('', 'nullval'))) continue;

                    $key = $this->getParam.'['.$field.']['.$type.']';
                    $params[$key] = $val;
                }
            }
        }

        return $params;
    }

    protected function importValue($val, $type) {

        if($val == 'nullval') return 'nullval';

        switch ($type) {
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
                $val = cot_import($val, 'D', 'INT');
                break;

            case 'float':
            case 'double':
            case 'decimal':
            case 'numeric':
                $val = cot_import($val, 'D', 'NUM');
                break;

            default:
                $val = cot_import($val, 'D', 'TXT');
        }

        return $val;
    }

    /**
     * Определить тип поля
     * @param $field
     * @return string|null
     */
    protected function fieldType($field) {

        if(isset($this->fields[$field]) && isset($this->fields[$field]['type'])) {
            return $this->fields[$field]['type'];
        }

        if(is_null($this->modelFields)) {
            $this->modelFields = array();
            if(!empty($this->modelName)){
                $modelName = $this->modelName;
                $this->modelFields = $modelName::fields();
            }
        }

        if(isset($this->modelFields[$field]) && isset($this->modelFields[$field]['type'])) {
            $type = $this->modelFields[$field]['type'];
            if(mb_strpos($type, 'decimal') !== false) {
                $type = 'double';
            }
            return $type;
        }

        return null;
    }

    /**
     * Generates a valid HTML ID based on name.
     *
     * @param string $name name from which to generate HTML ID
     *
     * @return string the ID generated based on name.
     */
    public static function getIdByName($name) {
        return str_replace(array('[]','][','[',']',' '), array('','_','_','','_'), $name);
    }

    protected function commonAttributes($field, $attributes = array()) {

        if(empty($attributes)) $attributes = array();
        foreach($attributes as $key => $val) {
            if(in_array(mb_strtolower($key), array('type', 'element', 'nomatter'))) unset($attributes[$key]);
        }

        if(isset($this->fields[$field]) && !empty($this->fields[$field])) {
            foreach($this->fields[$field] as $key => $val) {
                if(in_array(mb_strtolower($key), array('data', 'type', 'element', 'nomatter'))) continue;

                $attributes[$key] = $val;
            }

        }

        if (!empty($attributes['class'])) {
            if (mb_strpos($attributes['class'], 'form-control') === false) $attributes['class'] .= ' form-control';

        } else {
            $attributes['class'] = 'form-control';
        }

        return  $attributes;
    }

    // ===== Вывод элементов фильтров ======

    public function checklistbox($field, $config = array()) {
        $filter = 'checklistbox';
        $elName = $this->getParam."[{$field}][{$filter}]";
        //if(!isset($config['id']) || $config['id'] == '') $config['id'] = $this->getIdByName($elName);

        $attributes = array();

        $data = array();
        if(isset($config['data'])) $data = $config['data'];
        if(empty($data)) {
            if(isset($this->fields[$field]) && !empty($this->fields[$field]['data'])) $data = $this->fields[$field]['data'];
        }

        $value = array();
        if(isset($this->filters[$field][$filter])) $value = $this->filters[$field][$filter];

        $ret = cot_checklistbox($value, $elName, array_keys($data), array_values($data), $attributes, '', false);

        return $ret;
    }

    /**
     * Возвращает фильтр "Равно"
     *
     * @param string $field
     * @param array $config
     *
     * @return string
     */
    public function is($field, $config = array()) {
        $filter = 'is';
        $elName = $this->getParam."[{$field}][{$filter}]";

        $element = '';
        if(isset($config['element'])) $element = $config['element'];
        if(empty($element)) {
            if(isset($this->fields[$field]) && !empty($this->fields[$field]['element'])) $element = $this->fields[$field]['element'];
        }
        if(empty($element)) $element = 'text';

        if(in_array($element, array('text', 'select', 'radio'))) return $this->$element($field, $config, $filter);

        return $this->text($field, $config, $filter);
    }

    /**
     * Возвращает фильтр "Like"
     *
     * @param string $field
     * @param array $config
     *
     * @return string
     */
    public function like($field, $config = array()) {
        $filter = 'like';
        $elName = $this->getParam."[{$field}][{$filter}]";

        $attributes = $this->commonAttributes($field, $config);

        $value = '';
        if(isset($this->filters[$field][$filter])) $value = $this->filters[$field][$filter];

        return cot_inputbox('text', $elName, $value, $attributes);
    }

    /**
     * Возвращает фильтр "Больше чем" или Меньше чем
     * @param string $field
     * @param array $config
     *      list of attributes (name=>value) for the HTML element represented by this object.
     *      Может содержать поле 'data' - это данные для заполненния поля
     * @return string
     */
    public function moreLess($field, $config = array(), $filter = 'more') {
        $elName = $this->getParam."[{$field}][{$filter}]";
        if(!isset($config['id']) || $config['id'] == '') $config['id'] = $this->getIdByName($elName);

        $attributes = $this->commonAttributes($field, $config);

        $data = array();
        if(isset($config['data'])) $data = $config['data'];
        if(empty($data)) {
            if(isset($this->fields[$field]) && !empty($this->fields[$field]['data'])) $data = $this->fields[$field]['data'];
        }

        $element = '';
        if(isset($config['element'])) $element = $config['element'];
        if(empty($element)) {
            if(isset($this->fields[$field]) && !empty($this->fields[$field]['element'])) $element = $this->fields[$field]['element'];
        }


        $value = '';
        if(isset($this->filters[$field][$filter])) $value = $this->filters[$field][$filter];

//        if (isset($lf[$field]['more'])){
//            $min = $lf[$field]['more'];
//        }else{
//            if ($cat && $cat != 'all'){
//                $catlist = cot_structure_children('page', $cat);
//                $whereCond['cat'] = "page_cat IN ('".implode("','", $catlist)."')";
//            }
//
//            $where = "";
//            if (count($whereCond) > 0) $where = "WHERE (".implode(') AND (', $whereCond).")";
//
//            // Получить все значения:
//            $sql = "SELECT DISTINCT MIN(page_{$field}) FROM $db_pages $where ORDER BY `page_{$field}` ASC";
//            $min = $db->query($sql)->fetchColumn();
//        }

        if($element == 'select') {
            return $this->select($field, $config, $filter);

        } else {
            $ret = cot_inputbox('text', $elName, $value, $attributes);
        }

        return $ret;
    }

    /**
     * Возвращает фильтр "Больше чем"
     * @param string $field
     * @param array $config
     *      list of attributes (name=>value) for the HTML element represented by this object.
     *      Может содержать поле 'data' - это данные для заполненния поля
     * @return string
     */
    function more($field, $config = array()){
        return $this->moreLess($field, $config, 'more');
    }

    /**
     * Возвращает фильтр "Меньше чем"
     * @param string $field
     * @param array $config аналогично вышеописанным
     *
     * @return string
     */
    function less($field, $config = array()) {
        return $this->moreLess($field, $config, 'less');
    }

    public function radio($field, $config = array(), $filter = 'radio') {
        $elName = $this->getParam."[{$field}][{$filter}]";

        $data = array();
        if(isset($config['data'])) $data = $config['data'];
        if(empty($data)) {
            if(isset($this->fields[$field]) && !empty($this->fields[$field]['data'])) $data = $this->fields[$field]['data'];
        }

        $value = 'nullval';
        if(isset($this->filters[$field][$filter])) $value = $this->filters[$field][$filter];

        return cot_radiobox($value, $elName, array_keys($data), array_values($data));
    }

    /**
     * Возвращает фильтр "Выпадающий список"
     *
     * Самостоятельно этот фильтр не обрабатывается
     * Используйте так:
     * $filter->is('field_name', array('element' => 'select'))
     *
     * @param $field
     * @param array $config
     * @param string $filter
     * @return string
     */
    protected function select($field, $config = array(), $filter = 'select') {
        $elName = $this->getParam."[{$field}][{$filter}]";

        $attributes = $this->commonAttributes($field, $config);

        $data = array();
        if(isset($config['data'])) $data = $config['data'];
        if(empty($data)) {
            if(isset($this->fields[$field]) && !empty($this->fields[$field]['data'])) $data = $this->fields[$field]['data'];
        }

        $no_matter = $this->noMatter;
        if(isset($config['noMatter'])) {
            $no_matter = $config['noMatter'];
        } elseif(isset($this->fields[$field]) && isset($this->fields[$field]['noMatter'])) {
            $no_matter = $this->fields[$field]['noMatter'];
        }
        $options = array('nullval' => $no_matter);
        if(!empty($data)) {
            foreach($data as $key => $val) {
                $options[$key] = $val;
            }
        }

        $value = 'nullval';
        if(isset($this->filters[$field][$filter])) $value = $this->filters[$field][$filter];

        return cot_selectbox($value, $elName, array_keys($options), array_values($options), false, $attributes);
    }

    /**
     * Возвращает фильтр input type="text"
     *
     * Самостоятельно этот фильтр не обрабатывается
     * Используйте так:
     * $filter->is('field_name', array('element' => 'input'))
     *
     * @param string $field
     * @param array $config
     *
     * @return string
     */
    protected function text($field, $config = array(), $filter = 'input') {
        $elName = $this->getParam."[{$field}][{$filter}]";

        $attributes = $this->commonAttributes($field, $config);

        $value = '';
        if(isset($this->filters[$field][$filter])) $value = $this->filters[$field][$filter];

        return cot_inputbox('text', $elName, $value, $attributes);
    }
}