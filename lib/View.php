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
     * @return XTemplate $this object for call chaining
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
     * Return Script Path
     *
     * @param $base имя файла шаблона. Если не указано расширение или оно не входит в $_extensions, будет использовано
     *              '.php'
     * @param string $type
     * @param null $admin
     * @throws Exception
     *
     * @return string
     */
    public function scriptFile($base, $type = 'module', $admin = null) {

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
            if($admin){
                if(!empty(cot::$cfg['admintheme'])){
                    $this->_path[] = cot::$cfg['themes_dir']."/admin/".cot::$cfg['admintheme']."/plugins/";
                }
                $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme']."/admin/plugins/";
            }

            $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme'].DIRECTORY_SEPARATOR."plugins"
                .DIRECTORY_SEPARATOR;
            $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme'].DIRECTORY_SEPARATOR."plugins"
                .DIRECTORY_SEPARATOR.$base[0].DIRECTORY_SEPARATOR;
            $this->_path[] = cot::$cfg['plugins_dir'].DIRECTORY_SEPARATOR.$base[0].DIRECTORY_SEPARATOR."tpl".DIRECTORY_SEPARATOR;
        }
        elseif ($type == 'core' && in_array($base[0], array('admin', 'header', 'footer', 'message')))
        {
            // Built-in core modules
            if(!empty(cot::$cfg['admintheme'])){
                $this->_path[] = cot::$cfg['themes_dir']."/admin/".cot::$cfg['admintheme'].DIRECTORY_SEPARATOR;
            }
            $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme']."/admin/";
            $this->_path[] = cot::$cfg['system_dir'].DIRECTORY_SEPARATOR."admin/tpl/";
        }
        else
        {
            // Module template paths
            if($admin){
                if(!empty(cot::$cfg['admintheme'])){
                    $this->_path[] = cot::$cfg['themes_dir']."/admin/".cot::$cfg['admintheme']."/modules/";
                }
                $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme']."/admin/modules/";
            }
            $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme'].DIRECTORY_SEPARATOR;
            $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme'].DIRECTORY_SEPARATOR."modules"
                .DIRECTORY_SEPARATOR;
            $this->_path[] = cot::$cfg['themes_dir'].DIRECTORY_SEPARATOR.cot::$usr['theme'].DIRECTORY_SEPARATOR."modules"
                .DIRECTORY_SEPARATOR.$base[0].DIRECTORY_SEPARATOR;
            $this->_path[] = cot::$cfg['modules_dir'].DIRECTORY_SEPARATOR.$base[0].DIRECTORY_SEPARATOR."tpl".DIRECTORY_SEPARATOR;
        }

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
            throw new Exception("View script file not found: «".implode('.', $viewFile).$ext."»");
        }

        if ($return) {
            ob_start();
            ob_implicit_flush(false);
            require($scriptFile);
            return ob_get_clean();
        } else
            require($scriptFile);
    }

}