<?php
/**
 * Model is the base class for data models.
 *
 * Model implements the following commonly used features:
 *
 * - attribute declaration: by default, every public class member is considered as
 *   a model attribute
 * - attribute labels: each attribute may be associated with a label for display purpose
 * - massive attribute assignment
 * - scenario-based validation
 *
 * Model also raises the following events when performing data validation:
 *
 * - [[EVENT_BEFORE_VALIDATE]]: an event raised at the beginning of [[validate()]]
 * - [[EVENT_AFTER_VALIDATE]]: an event raised at the end of [[validate()]]
 *
 * You may directly use Model to store model data, or extend it with customization.
 *
 * @author  Kalnov Alexey <kalnov_alexey@yandex.ru> http://portal30.ru
 */
abstract class Som_Model_Abstract extends Component
{

    /**
     * @event ModelEvent an event raised at the beginning of [[validate()]]. You may set
     * [[ModelEvent::isValid]] to be false to stop the validation.
     */
    const EVENT_BEFORE_VALIDATE = 'beforeValidate';

    /**
     * @event Event an event raised at the end of [[validate()]]
     */
    const EVENT_AFTER_VALIDATE = 'afterValidate';

    const EVENT_BEFORE_SET_DATA = 'beforeSetData';
    const EVENT_AFTER_SET_DATA  = 'afterSetData';

    /**
     * Данные в соотвествии с полями
     * @var array
     */
    protected $_data = array();

    /**
     * Массив, для хранения временных данных не описанных в полях
     * Например $user->auth_write = true
     * @var array
     */
    protected $_extraData = array();


    /**
     * @var array Список полей, описанный в моделе.
     */
    protected static $fields = array();

    public $showValidateErrors = true;
    protected $validators = array();
    protected $ignoreValidateFields = array();

    /**
     * @var array
     */
    protected $_errors = array();

    /**
     * @param array $data данные для инициализации модели
     */
    public function __construct($data = null)
    {
        // Инициализация полей
        $fields = static::fields();
        foreach ($fields as $name => $field) {
            // Дефолтное значение
            $this->_data[$name] = isset($field['default']) ? $field['default'] : null;
        }

        $this->init($data);

        // Заполняем существующие поля строго переданными значениями. Никаких сеттеров
        if (!is_null($data)) {
            foreach ($data as $key => $value) {
                if (in_array($key, static::getColumns())) {
                    $this->_data[$key] = $value;

                } elseif (!isset($fields[$key])) {
                    // Пробрасываем дополнительные значения
                    $this->_extraData[$key] = $value;
                }
            }
        }
    }

    /**
     * Initializes the object.
     * @param array $data данные для инициализации модели
     */
    protected function init(&$data = null)
    {
    }

    // ==== Методы для манипуляции с данными ====
    /**
     * Returns the value of a component property.
     * This method will check in the following order and act accordingly:
     *
     *  - a property defined by a getter: return the getter result
     *  - a property of a behavior: return the behavior property value
     *
     * Do not call this method directly as it is a PHP magic method that
     * will be implicitly called when executing `$value = $component->property;`.
     * @param string $name the property name
     * @return mixed the property value or the value of a behavior's property
     * @throws Exception_UnknownProperty if the property is not defined
     * @throws Exception_InvalidCall if the property is write-only.
     * @see __set()
     */
    public function __get($name)
    {
        $getter = 'get' . ucfirst($name);
        if (method_exists($this, $getter)) {
            // read property, e.g. getName()
            return $this->$getter();
        } else {
            // behavior property
            $this->ensureBehaviors();
            foreach ($this->_behaviors as $behavior) {
                if ($behavior->canGetProperty($name)) {
                    return $behavior->$name;
                }
            }
        }

        if (isset($this->_data[$name]))      return $this->_data[$name];
        if (isset($this->_extraData[$name])) return $this->_extraData[$name];

//        if (method_exists($this, 'set' . ucfirst($name))) {
//            throw new Exception_InvalidCall('Getting write-only property: ' . get_class($this) . '::' . $name);
//        } else {
//            throw new Exception_UnknownProperty('Getting unknown property: ' . get_class($this) . '::' . $name);
//        }

        return null;
    }


    /**
     * Sets the value of a component property.
     * This method will check in the following order and act accordingly:
     *
     *  - a property defined by a setter: set the property value
     *  - an event in the format of "on xyz": attach the handler to the event "xyz"
     *  - a behavior in the format of "as xyz": attach the behavior named as "xyz"
     *  - a property of a behavior: set the behavior property value
     *
     * Do not call this method directly as it is a PHP magic method that
     * will be implicitly called when executing `$component->property = $value;`.
     * @param string $name the property name or the event name
     * @param mixed $value the property value
     * @see __get()
     */
    public function __set($name, $value)
    {
        $setter = 'set' . ucfirst($name);
        if (method_exists($this, $setter)) {
            // set property
            $this->$setter($value);

            return;
        } elseif (strncmp($name, 'on ', 3) === 0) {
            // on event: attach event handler
            $this->on(trim(substr($name, 3)), $value);

            return;
        } elseif (strncmp($name, 'as ', 3) === 0) {
            // as behavior: attach behavior
            $name = trim(substr($name, 3));
            $this->attachBehavior($name, $value instanceof Behavior ? $value : new $value() );

            return;
        } else {
            // behavior property
            $this->ensureBehaviors();
            foreach ($this->_behaviors as $behavior) {
                if ($behavior->canSetProperty($name)) {
                    $behavior->$name = $value;

                    return;
                }
            }
        }

        if (in_array($name, static::getColumns())) {
            $this->_data[$name] = $value;

            return;
        }

        $this->_extraData[$name] = $value;
    }

    /**
     * Checks if a property is set, i.e. defined and not null.
     * This method will check in the following order and act accordingly:
     *
     *  - a property defined by a setter: return whether the property is set
     *  - a property of a behavior: return whether the property is set
     *  - return `false` for non existing properties
     *
     * Do not call this method directly as it is a PHP magic method that
     * will be implicitly called when executing `isset($component->property)`.
     * @param string $name the property name or the event name
     * @return boolean whether the named property is set
     * @see http://php.net/manual/en/function.isset.php
     */
    public function __isset($name)
    {
        $methodName = 'isset' . ucfirst($name);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        try {
            $tmp = $this->__get($name);
            return $tmp !== null;
        } catch (Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Sets a component property to be null.
     * This method will check in the following order and act accordingly:
     *
     *  - a property defined by a setter: set the property value to be null
     *  - a property of a behavior: set the property value to be null
     *
     * Do not call this method directly as it is a PHP magic method that
     * will be implicitly called when executing `unset($component->property)`.
     * @param string $name the property name
     * @throws Exception_InvalidCall if the property is read only.
     * @see http://php.net/manual/en/function.unset.php
     */
    public function __unset($name)
    {
        $methodName = 'unset' . ucfirst($name);
        if (method_exists($this, $methodName)) {
            return $this->$methodName();
        }

        $setter = 'set' . ucfirst($name);
        if (method_exists($this, $setter)) {
            $this->$setter(null);
            return;
        } else {
            // behavior property
            $this->ensureBehaviors();
            foreach ($this->_behaviors as $behavior) {
                if ($behavior->canSetProperty($name)) {
                    $behavior->$name = null;
                    return;
                }
            }
        }

        if (isset($this->_data[$name])) {
            unset($this->_data[$name]);
            return;
        }
        if (isset($this->_extraData[$name])) {
            unset($this->_extraData[$name]);
            return;
        }

        throw new Exception_InvalidCall('Unsetting an unknown or read-only property: ' . get_class($this) . '::' . $name);
    }

    protected function beforeSetData(&$data)
    {
        $className = get_called_class();
        $return = true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.'.self::EVENT_BEFORE_SET_DATA) as $pl) {
            include $pl;
        }
        /* ===== */

        $event = new ModelEvent;
        $event->data['data'] = $data;

        $this->trigger(self::EVENT_BEFORE_SET_DATA, $event);

        return $return && $event->isValid;
    }

    /**
     * Populates the model with input data.
     *
     * with `setData()` can be written as:
     *
     * ```php
     * if ($model->setData($_POST) && $model->save()) {
     *     // handle success
     * }
     * ```
     *
     * @param array|Som_Model_ActiveRecord $data
     * @param bool $safe безопасный режим
     *
     * @throws Exception
     * @return bool
     */
    public function setData($data, $safe = true)
    {
        $fields = static::fields();

        if (!$this->beforeSetData($data)) {
            return false;
        }

        $class = get_class($this);
        if ($data instanceof $class)
            $data = $data->toArray();
        if (!is_array($data)) {
            throw new  Exception("Data must be an Array or instance of $class Class");
        }

        $path = trim($_SERVER['REQUEST_URI'], '/');

        if (mb_strpos($path, '?') !== false) {
            $path = mb_substr($path, 0, mb_strpos($path, '?'));
        }

        $mPath = $path ? explode("/", $path) : array();
        foreach ($data as $key => $value) {
            if ($safe && isset($fields[$key]['safe']) && $fields[$key]['safe']) {
                if(!cot::$usr['isadmin'] && cot::$env['ext'] != 'admin'){
                    throw new Exception("Trying to write value «{$value}» to protected field «{$key}» of model «{$class}»");
                }
            }

            if (is_string($value)) $value = trim($value);
            $this->__set($key, $value);
        }

        $this->afterSetData();

        return true;
    }

    protected function afterSetData()
    {
        $className = get_called_class();

        /* === Hook === */
        foreach (cot_getextplugins($className.'.'.self::EVENT_AFTER_SET_DATA) as $pl) {
            include $pl;
        }
        /* ===== */

        $this->trigger(self::EVENT_AFTER_SET_DATA);
    }
    // ==== /Методы для манипуляции с данными ====

    // ==== Методы для работы с полями ====
    /**
     * Возвращает описание поля
     * @param $column
     * @return string
     */
    public static function fieldLabel($column)
    {
        $fields = static::fields();
        if (isset($fields[$column]['description'])) return $fields[$column]['description'];

        return '';
    }

    /**
     * Возвращает все поля, включая дополнительные
     *
     * @param bool $real получить поля напрямую из таблицы
     * @param bool $cache Использовать кеш периода выполнения
     *
     * @return array
     */
    public static function fields($cache = true)
    {
        $className = get_called_class();

        if ($cache && !empty(self::$fields[$className])) return self::$fields[$className];

        self::$fields[$className] = static::fieldList();

        return self::$fields[$className];
    }


    /**
     * Получить все поля из БД
     * Метод очень затратный по рессурсам. Кеширование на период выполнения необходимо
     *
     * @param bool $real получить поля напрямую из таблицы
     * @param bool $cache Использовать кеш периода выполнения
     *
     * @return null|array
     */
    public static function getColumns($cache = true)
    {
        $className = get_called_class();

        static $cols = array();

        if ($cache && !empty($cols[$className])) return $cols[$className];

        $fields = static::fields($cache);
        // Не включаем связи ко многим и, также, указывающие на другое поле
        $cols[$className] = array();
        foreach ($fields as $name => $field) {
//            if (!isset($field['link']) ||
//                (in_array($field['link']['relation'], array(Som::TO_ONE, Som::TO_ONE_NULL)) && !isset($field['link']['localKey']))
//            ) {
                $cols[$className][] = $name;
//            }
        }

        return $cols[$className];
    }

    /**
     * Получить значение поля "Как есть"
     * @param $column
     * @return mixed
     */
    public function rawValue($column)
    {
        if (isset($this->_data[$column])) {
            return $this->_data[$column];
        }

        return null;
    }

    /**
     * Возвращает список названий полей, обязательных для заполнения
     *
     * @return array
     */
    public function requiredFields()
    {
        $requiredFields = array();
        $validators = $this->validators();
        foreach ($validators as $params) {
            $fieldList = $params[0];
            unset($params[0]);
            $fieldList = explode(",", $fieldList);
            $fieldList = array_map("trim", $fieldList);
            foreach ($fieldList as $field) {
                foreach ($params as $validators) {
                    if ($validators == 'required') $requiredFields[] = $field;
                }
            }
        }

        $fields = static::fields();
        foreach ($fields as $name => $field) {
            if (isset($field['nullable']) && !$field['nullable']) $requiredFields[] = $name;
        }

        return $requiredFields;
    }
    // ==== /Методы для работы с полями ====

    // ==== Методы для Валидации ====
    protected function validators()
    {
        return array();
    }

    /**
     * Removes errors for all attributes or a single attribute.
     * @param string $field attribute name. Use null to remove errors for all attribute.
     */
    public function clearErrors($field = null)
    {
        if ($field === null) {
            $this->_errors = array();
        } else {
            unset($this->_errors[$field]);
        }
    }

    /**
     * This method is invoked before validation starts.
     * The default implementation raises a `beforeValidate` event.
     * You may override this method to do preliminary checks before validation.
     * Make sure the parent implementation is invoked so that the event can be raised.
     *
     * @param array $validateFields
     * @return boolean whether the validation should be executed. Defaults to true.
     * If false is returned, the validation will stop and the model is considered invalid.
     */
    public function beforeValidate($validateFields = null)
    {
        $className = get_called_class();
        $return = true;

        /* === Hook === */
        foreach (cot_getextplugins($className.'.'.self::EVENT_BEFORE_VALIDATE) as $pl) {
            include $pl;
        }
        /* ===== */

        $event = new ModelEvent;
        $event->data['validateFields'] = $validateFields;
        $this->trigger(self::EVENT_BEFORE_VALIDATE, $event);

        return $return && $event->isValid;
    }

    /**
     * Performs the model data validation.
     * You may override this method in your models if you need to create special validation
     *
     * This method will call [[beforeValidate()]] and [[afterValidate()]] before and
     * after the actual validation, respectively. If [[beforeValidate()]] returns false,
     * the validation will be cancelled and [[afterValidate()]] will not be called.
     *
     * @param array $validateFields list of fields that should be validated. If NULL - all fields will be validated.
     * @param bool  $errorMessages  whether to call cot_error() for found errors
     * @param bool  $clearErrors    whether to call [[clearErrors()]] before performing validation
     *
     * @return bool
     * @throws Exception
     *
     * @todo дописать метод
     */
    public function validate($validateFields = null, $errorMessages = null, $clearErrors = true)
    {
        if ($clearErrors) $this->clearErrors();

        if (!$this->beforeValidate()) return false;

        if (is_null($errorMessages)) $errorMessages = $this->showValidateErrors;

        $validators = $this->validators();
        $fields = static::fields();

        foreach ($this->requiredFields() as $field) {
            $validators[] = array($field, 'required');
        }

        $m_validators = array();
        foreach ($validators as $params) {
            $fieldList = $params[0];
            unset($params[0]);
            $fieldList = explode(",", $fieldList);
            $fieldList = array_map("trim", $fieldList);
            foreach ($fieldList as $field) {
                foreach ($params as $validators) {
                    if (empty($m_validators[$field]) || !in_array($validators, $m_validators[$field])) {
                        $m_validators[$field][] = $validators;
                    }
                }
            }
        }
        $this->validators = array_merge($this->validators, $m_validators);


        if (empty($validateFields)) {
            // Получить все поля модели
            $validateFields = static::getColumns();
            foreach ($fields as $name => $fld) {
                if (!in_array($name, $validateFields)) $validateFields[] = $name;
            }
        }
        $validateFields = array_merge($validateFields, array_keys($this->validators));
        $validateFields = array_unique($validateFields);

        foreach ($validateFields as $name) {
            if (in_array($name, $this->ignoreValidateFields)) continue;
            $value = null;
            if (isset($this->_data[$name])) {
                //$value = $this->_data[$name];
                // не срабатывают валидаторы при навешивании модификаций,адаптеров для полей.
                // например: не работает при версировании полей.
                $value = $this->$name; // Не изменять. Протестировано и работает. Если Геттер вдруг не запускается
                // на поле Personal_Model_Vacancy::$active, это значит, что php повторно не вызывает геттер, если
                // в стеке вызовов он уже есть. такой функционал нужно реализовывать в событии onAfterFetch()
                // в нем и производить деактивацию по дате.

            } elseif (isset($this->_extraData[$name])) {
                $value = $this->$name;
            }
//                elseif (isset($fields[$name]) && $fields[$name]['type'] == 'link') {
//                    $list = (array)$fields[$name]['link'];
//                    // Многие ко многим
//                    if (in_array($list['relation'], array(Som::TO_MANY, Som::TO_MANY_NULL)) &&
//                        !empty($this->_linkData[$name])
//                    ) {
//                        $value = $this->_linkData[$name];
//                    }
//                }
            $fieldName = $name;
            $tmp = static::fieldLabel($name);
            if (!empty($tmp)) $fieldName = $tmp;

            if (isset($this->validators[$name]) && count($this->validators[$name]) > 0) {
                foreach ($this->validators[$name] as $validator) {
                    // Проверка на Validator_Abstract
                    if ($validator instanceof Validator_Abstract) {
                        $validator->setModel($this);
                        $validator->setField($name);
                        if (!$validator->isValid($value)) {
                            $error = implode(', ', $validator->getMessages());
                            $this->addErrorMessage($name, $error);
                        }

                    } elseif (is_callable($validator) && $value) {
                        // Проверка на callback
                        try {
                            $res = call_user_func_array($validator, array($value));
                            if ($res !== true) $this->addErrorMessage($name, $res);

                        } catch (Exception $e) {
                            throw new Exception("Не правильный CallBack validator для поля '{$name}'");
                        }

                    } elseif (is_string($validator)) {
                        switch (mb_strtolower($validator)) {
                            case 'required':
                                $this->_requiredFields[$name] = 1;
                                if (($value === '') || (is_null($value))) {
                                    // Спасибо Дмитрию за найденную ошибку.
                                    $error = 'Обязательное для заполнения' . ': ' . $fieldName;
                                    $this->addErrorMessage($name, $error);
                                }
                                break;
                        }
                    }
                }
            }

            // Проверка на соотвествие типу
            if (!empty($fields[$name])) {
                switch (mb_strtolower($fields[$name]['type'])) {
                    case 'int':
                    case 'integer':
                    case 'bigint':
                        // TODO
                        break;
                }
            }
        }

        $this->afterValidate($validateFields);

        // Системные сообщения об ошибках
        if (count($this->_errors) > 0 && $errorMessages) {
            foreach ($this->_errors as $name => $errors) {
                if (!empty($errors)) {
                    foreach ($errors as $errorRow) {
                        if (!empty($errorRow)) cot_error($errorRow, $name);
                    }
                }
            }
        }

        return count($this->_errors) ? false : true;
    }

    /**
     * This method is invoked after validation ends.
     * The default implementation raises an `afterValidate` event.
     * You may override this method to do postprocessing after validation.
     * Make sure the parent implementation is invoked so that the event can be raised.
     *
     * @param array $validateFields
     */
    public function afterValidate($validateFields = null)
    {
        $className = get_called_class();

        /* === Hook === */
        foreach (cot_getextplugins($className.'.'.self::EVENT_AFTER_VALIDATE) as $pl) {
            include $pl;
        }
        /* ===== */

        $event = new ModelEvent;
        $event->data['validateFields'] = $validateFields;
        $this->trigger(self::EVENT_AFTER_VALIDATE, $event);
    }

    public function addErrorMessage($field, $message = null)
    {
        if ($message && isset($this->_errors[$field]) ) {
            if (!in_array($message, $this->_errors[$field])) {
                $this->_errors[$field][] = $message;
            }

        } else {
            $this->_errors[$field][] = $message;
        }
    }
    // ==== /Методы для Валидации ====

    /**
     * Конфиг модели. Информация обо всех полях
     *
     * @return array
     */
    public static function fieldList()
    {
        return array();
    }
}