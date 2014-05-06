<?php
/**
 * Autoloader
 * @author Kalnov Alexey http://portal30.ru
 */
class Loader{

    /**
     * Register the autoloader with spl_autoload_register()
     */
    public static function register(){

        static $registered = false;
        if(!$registered){
            spl_autoload_register(array('Loader', 'autoload'));
            $registered = true;
        }

    }

    /**
     * Autoload a class
     *
     * @param  string $class
     * @return bool
     */
    public static function autoload($class){

        return self::loadClass($class);

    }

    /**
     * Loads a class from a PHP file.  The filename must be formatted
     * as "$class.php".
     *
     * If $dirs is a string or an array, it will search the directories
     * in the order supplied, and attempt to load the first matching file.
     *
     * If $dirs is null, it will split the class name at underscores to
     * generate a path hierarchy (e.g., "Zend_Example_Class" will map
     * to "Zend/Example/Class.php").
     *
     * If the file was not found in the $dirs, or if no $dirs were specified,
     * it will attempt to load it from PHP's include_path.
     *
     * @param string $class      - The full class name of a Zend component.
     * @param string|array $dirs - OPTIONAL Either a path or an array of paths
     *                             to search.
     * @return void
     * @throws Exception
     */
    public static function loadClass($class, $dirs = null)
    {
        if (class_exists($class, false) || interface_exists($class, false)) {
            return;
        }
        if ((null !== $dirs) && !is_string($dirs) && !is_array($dirs)) {
            throw new Exception('Directory argument must be a string or an array');
        }

        // Autodiscover the path from the class name
        // Implementation is PHP namespace-aware, and based on
        // Framework Interop Group reference implementation:
        // http://groups.google.com/group/php-standards/web/psr-0-final-proposal
        $className = ltrim($class, '\\');
        $file      = '';
        $namespace = '';
        if ($lastNsPos = strripos($className, '\\')) {
            $namespace = substr($className, 0, $lastNsPos);
            $className = substr($className, $lastNsPos + 1);
            $file      = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
        }
        $file .= str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php';

        if (!empty($dirs)) {
            // use the autodiscovered path
            $dirPath = dirname($file);
            if (is_string($dirs)) {
                $dirs = explode(PATH_SEPARATOR, $dirs);
            }
            foreach ($dirs as $key => $dir) {
                if ($dir == '.') {
                    $dirs[$key] = $dirPath;
                } else {
                    $dir = rtrim($dir, '\\/');
                    $dirs[$key] = $dir . DIRECTORY_SEPARATOR . $dirPath;
                }
            }
            $file = basename($file);
            return self::loadFile($file, $dirs, true);
        } else {
            return self::loadFile($file, null, true);
        }

        return false;
    }

    /**
     * Loads a PHP file.  This is a wrapper for PHP's include() function.
     *
     * $filename must be the complete filename, including any
     * extension such as ".php".  Note that a security check is performed that
     * does not permit extended characters in the filename.  This method is
     * intended for loading Zend Framework files.
     *
     * If $dirs is a string or an array, it will search the directories
     * in the order supplied, and attempt to load the first matching file.
     *
     * If the file was not found in the $dirs, or if no $dirs were specified,
     * it will attempt to load it from PHP's include_path.
     *
     * If $once is TRUE, it will use include_once() instead of include().
     *
     * @param  string        $filename
     * @param  string|array  $dirs - OPTIONAL either a path or array of paths
     *                       to search.
     * @param  boolean       $once
     * @return boolean
     * @throws Exception
     */
    public static function loadFile($filename, $dirs = null, $once = false){
        global $cfg;
        self::securityCheck($filename);

        /**
         * Search in provided directories, as well as include_path
         */
        $incDirs = array();
        if(empty($dirs)) $incDirs = self::explodeIncludePath();
        if(empty($dirs)) $dirs = array();
        $dirs[] = $cfg['modules_dir'];
        $dirs[] = $cfg['plugins_dir'];
        $dirs[] = dirname(__FILE__); //'lib' directory;
        $dirs = array_merge($dirs, $incDirs);
        if(empty($dirs)) return false;

        /**
         * Try finding for the file
         */
        foreach($dirs as $dir){
            if($dir[(mb_strlen($dir) - 1)] != DIRECTORY_SEPARATOR && $dir[(mb_strlen($dir) - 1)] != '/'){
                $dir .= DIRECTORY_SEPARATOR;
            }
            $file = $dir.$filename;
            if(file_exists($file)){
                if ($once) {
                    include_once $file;
                } else {
                    include $file;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Explode an include path into an array
     *
     * If no path provided, uses current include_path. Works around issues that
     * occur when the path includes stream schemas.
     *
     * @param  string|null $path
     * @return array
     */
    public static function explodeIncludePath($path = null)
    {
        if (null === $path) {
            $path = get_include_path();
        }

        if (PATH_SEPARATOR == ':') {
            // On *nix systems, include_paths which include paths with a stream
            // schema cannot be safely explode'd, so we have to be a bit more
            // intelligent in the approach.
            $paths = preg_split('#:(?!//)#', $path);
        } else {
            $paths = explode(PATH_SEPARATOR, $path);
        }
        return $paths;
    }

    /**
     * Ensure that filename does not contain exploits
     *
     * @param  string $filename
     * @return void
     * @throws Exception
     */
    protected static function securityCheck($filename)
    {
        /**
         * Security check
         */
        if (preg_match('/[^a-z0-9\\/\\\\_.:-]/i', $filename)) {
            throw new Exception('Security check: Illegal character in filename');
        }
    }
}
