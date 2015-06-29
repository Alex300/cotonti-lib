<?php
/**
 * View Class
 * @author Kalnov Alexey http://portal30.ru
 */
class View{

    private $_path = array();
    private $_strictVars = false;

    /**
     * @var array Allowed extensions
     */
    protected $_extensions = array('php', 'phtml', 'tpl');

    protected $_vars = array();

    protected $themeDir = '';

    /**
     * @var bool вызывался ли метод вывода сообщений displayMessages() для текущего шаблона
     * @see View::displayMessages()
     */
    protected $messagesDisplayed = false;

    function __construct() {
        $this->themeDir = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme'].DIRECTORY_SEPARATOR;
    }

    public function themeDir(){
        return $this->themeDir;
    }

    public function __get($key) {
        if (isset($this->_vars[$key])) return $this->_vars[$key];

        if ($this->_strictVars) {
            trigger_error('Key "' . $key . '" does not exist', E_USER_NOTICE);
        }

        return null;
    }

    public function __set($key, $val) {
        if ('_' != substr($key, 0, 1)) {
            $this->_vars[$key] = $val;

            return;
        }

        throw new Exception("Не верное имя переменной, переменная не должна начинатся с '_'");
    }

    /**
     * isset() handler for object properties.
     *
     * @param string $key
     * @throws Exception
     * @return bool
     */
    public function __isset($key) {
        if ('_' != substr($key, 0, 1)) {
            return isset($this->_vars[$key]);
        }

        throw new Exception("Не верное имя переменной, переменная не должна начинатся с '_'");
    }

    /**
     * unset() handler for object properties.
     *
     * @param string $key Var name
     * @return mixed|void
     * @throws Exception
     */
    public function __unset($key) {
        if ('_' != substr($key, 0, 1)) {
            if (isset($this->_vars[$key])) unset($this->_vars[$key]);
            return;
        }

        throw new Exception("Не верное имя переменной, переменная не должна начинатся с '_'");
    }


    /**
     * CoTemplate like assigns a template variable or an array of them
     *
     * @param mixed $name Variable name or array of values
     * @param mixed $val Tag value if $name is not an array
     * @param string $prefix An optional prefix for variable keys
     * @return View $this object for call chaining
     */
    public function assign($name, $val = NULL, $prefix = ''){
        if (is_array($name)){
            foreach ($name as $key => $val){
                $this->__set($prefix.$key, $val);
            }
        }else{
            $this->__set($prefix.$name, $val);
        }
        return $this;
    }

    /**
     * @param string|array $path The path specification.
     *
     * @param bool $prepend  add to the top of the stack?
     * @return View
     */
    public function addScriptPath($path, $prepend = true) {
        foreach ((array)$path as $dir) {
            // attempt to strip any possible separator and
            // append the system directory separator
            $dir = rtrim($dir, '/');
            $dir = rtrim($dir, '\\');
            $dir .= DIRECTORY_SEPARATOR;

            foreach($this->_path as $key => $val){
                if($this->_path[$key] == $dir) unset($this->_path[$key]);
            }

            if($prepend){
                // add to the top of the stack.
                array_unshift($this->_path, $dir);
            }else{
                $this->_path[] = $dir;
            }
        }

        return $this;
    }

    /**
     * Return Template Script Path
     *
     * @param $base имя файла шаблона. Если не указано расширение или оно не входит в $_extensions, будет использовано
     *              '.php'
     *              The default search order is:
     *                1) Current theme folder (plugins/ subdir for plugins, admin/ subdir for admin)
     *                2) Default theme folder (if current is not default)
     *                3) tpl subdir in module/plugin folder (fallback template)
     * @param string $type Extension type: 'plug', 'module' or 'core'
     * @param null $admin  Use admin theme file if present. Tries to determine from viewFile string by default.
     * @param string $type
     * @param null $admin
     * @throws Exception
     *
     * @return string
     */
    public function scriptFile($base, $type = 'module', $admin = null) {
        global $cfg, $usr;

        // Get base name parts
        if (is_string($base) && mb_strpos($base, '.') !== false)
        {
            $base = explode('.', $base);
        }
        if (!is_array($base))
        {
            $base = array($base);
        }
        if (is_null($admin))
        {
            $admin = ($base[0] == 'admin' || ($base[1] && $base[1] == 'admin'));
        }
        $scan_dirs = array();

        // Possible search directories depending on extension type
        if ($type == 'plug')
        {
            // Plugin template paths
            $admin && !empty($cfg['admintheme']) && $scan_dirs[] = "{$cfg['themes_dir']}/admin/{$cfg['admintheme']}/plugins/";
            $admin && $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/admin/plugins/";
            $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/plugins/";
            $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/plugins/{$base[0]}/";
            $scan_dirs[] = "{$cfg['plugins_dir']}/{$base[0]}/tpl/";
        }
        elseif ($type == 'core' && in_array($base[0], array('admin', 'header', 'footer', 'message')))
        {
            // Built-in core modules
            !empty($cfg['admintheme']) && $scan_dirs[] = "{$cfg['themes_dir']}/admin/{$cfg['admintheme']}/";
            $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/admin/";
            $scan_dirs[] = "{$cfg['system_dir']}/admin/tpl/";
        }
        else
        {
            // Module template paths
            $admin && !empty($cfg['admintheme']) && $scan_dirs[] = "{$cfg['themes_dir']}/admin/{$cfg['admintheme']}/modules/";
            $admin && $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/admin/modules/";
            $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/";
            $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/modules/";
            $scan_dirs[] = "{$cfg['themes_dir']}/{$usr['theme']}/modules/{$base[0]}/";
            $scan_dirs[] = "{$cfg['modules_dir']}/{$base[0]}/tpl/";
        }

        if(!empty($scan_dirs)) $this->addScriptPath($scan_dirs, false);

        // Build template file name from base parts glued with dots
        $base_depth = count($base);
        $ext = '';
        if(!in_array($base[(count($base) - 1)], $this->_extensions)){
            $ext = '.php';
        }
        for ($i = $base_depth; $i > 0; $i--){
            $levels = array_slice($base, 0, $i);

            $themefile = implode('.', $levels) . $ext;
            // Search in all available directories
            foreach ($this->_path as $dir){
                if (is_readable($dir . $themefile)) return $dir . $themefile;
            }
        }

        // Поддержка абсолютных путей
        $themefile = implode('.', $base);
        if(is_readable($themefile)) return $themefile;

        return false;
    }

    /**
     * Renders different messages on page
     *  wrapper for cot_display_messages()
     *
     * @param bool $cache - "Просросить" сообщения об ошибках, чтобы подсвечивать элементы формы
     * @param string $tpl
     * @throws Exception
     * @return string
     * @see cot_display_messages()
     */
    public function displayMessages($cache = true, $tpl = ''){
        if($tpl == ''){
            if(defined('COT_ADMIN')) {
                $tpl = cot::$cfg['system_dir'].DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'tpl'.DIRECTORY_SEPARATOR.'warnings.tpl';
                if(!empty(cot::$cfg['admintheme'])) {
                    $file = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.cot::$cfg['admintheme'].
                        DIRECTORY_SEPARATOR.'warnings.tpl';
                    if(file_exists($file)) $tpl = $file;
                }
            } else {
                $tpl = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme'].DIRECTORY_SEPARATOR.'warnings.tpl';
                if(!file_exists($tpl)){
                    $tpl = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$cfg['defaulttheme'].DIRECTORY_SEPARATOR.'warnings.tpl';
                }
            }
        }
        if(!file_exists($tpl)){
            throw new Exception('Messages/Warning template not found');
        }

        $raw_tpl = file_get_contents($tpl);

        // Закешируем сообщения на период выполнения
        $currentMessages = cot_get_messages('');

        $t = new XTemplate();
        $t->compile('<!-- BEGIN: MAIN-->'.$raw_tpl.'<!-- END: MAIN-->');

        cot_display_messages($t);

        // "Пробросим" сообщения об ошибках назад, чтобы скрипт вида мог подсвечивать элементы форм с ошибками
        if($cache) {
            $_SESSION['cot_messages'][cot::$sys['site_id']] = $currentMessages;
            $this->messagesDisplayed = true;
        }

        $t->parse();
        $t->out();
    }

    public function template($script, $vars = array()) {
        $view = new View();

        if(!empty($this->_path)) {
            foreach ($this->_path as $path) {
                $view->addScriptPath($path);
            }
        }
        foreach ($this->_vars as $key => $var) {
            $view->$key = $var;
        }
        foreach($vars as $key => $var) {
            $view->$key = $var;
        }
        return $view->render($script);
    }

    /**
     * @param array|string $viewFile имя файла шаблона. Если не указано расширение или оно не входит в $_extensions,
     *              будет использовано '.php'
     *              The default search order is:
     *                1) Current theme folder (plugins/ subdir for plugins, admin/ subdir for admin)
     *                2) Default theme folder (if current is not default)
     *                3) tpl subdir in module/plugin folder (fallback template)
     * @param string $type Extension type: 'plug', 'module' or 'core'
     * @param null   $admin  Use admin theme file if present. Tries to determine from viewFile string by default.
     * @param bool   $return Вернуть как строку?
     * @return string
     * @throws Exception
     */
    public function render($viewFile, $type = 'module', $admin = null, $return = true) {

        $scriptFile = $this->scriptFile($viewFile, $type, $admin);
        if(empty($scriptFile)){
            if (is_string($viewFile) && mb_strpos($viewFile, '.') !== false){
                $viewFile = explode('.', $viewFile);
            }
            if (!is_array($viewFile)){
                $viewFile = array($viewFile);
            }
            $ext = '';
            if(!in_array($viewFile[(count($viewFile) - 1)], $this->_extensions)){
                $ext = '.php';
            }
            throw new Exception('View script file not found: "'.implode('.', $viewFile).$ext.'"');
        }

        if ($return) {
            ob_start();
            ob_implicit_flush(false);
            require($scriptFile);

            if($this->messagesDisplayed) cot_clear_messages();

            return ob_get_clean();
        } else {
            require($scriptFile);
        }

        if($this->messagesDisplayed) cot_clear_messages();
    }

}