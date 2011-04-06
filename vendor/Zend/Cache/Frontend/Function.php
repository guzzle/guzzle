<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Frontend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Function.php 22648 2010-07-20 14:43:27Z mabe $
 */


/**
 * @see Zend_Cache_Core
 */
// require_once 'Zend/Cache/Core.php';


/**
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Frontend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Cache_Frontend_Function extends Zend_Cache_Core
{
    /**
     * This frontend specific options
     *
     * ====> (boolean) cache_by_default :
     * - if true, function calls will be cached by default
     *
     * ====> (array) cached_functions :
     * - an array of function names which will be cached (even if cache_by_default = false)
     *
     * ====> (array) non_cached_functions :
     * - an array of function names which won't be cached (even if cache_by_default = true)
     *
     * @var array options
     */
    protected $_specificOptions = array(
        'cache_by_default' => true,
        'cached_functions' => array(),
        'non_cached_functions' => array()
    );

    /**
     * Constructor
     *
     * @param  array $options Associative array of options
     * @return void
     */
    public function __construct(array $options = array())
    {
        while (list($name, $value) = each($options)) {
            $this->setOption($name, $value);
        }
        $this->setOption('automatic_serialization', true);
    }

    /**
     * Main method : call the specified function or get the result from cache
     *
     * @param  callback $callback         A valid callback
     * @param  array    $parameters       Function parameters
     * @param  array    $tags             Cache tags
     * @param  int      $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @param  int      $priority         integer between 0 (very low priority) and 10 (maximum priority) used by some particular backends
     * @return mixed Result
     */
    public function call($callback, array $parameters = array(), $tags = array(), $specificLifetime = false, $priority = 8)
    {
        if (!is_callable($callback, true, $name)) {
            Zend_Cache::throwException('Invalid callback');
        }

        $cacheBool1 = $this->_specificOptions['cache_by_default'];
        $cacheBool2 = in_array($name, $this->_specificOptions['cached_functions']);
        $cacheBool3 = in_array($name, $this->_specificOptions['non_cached_functions']);
        $cache = (($cacheBool1 || $cacheBool2) && (!$cacheBool3));
        if (!$cache) {
            // Caching of this callback is disabled
            return call_user_func_array($callback, $parameters);
        }

        $id = $this->_makeId($callback, $parameters);
        if ( ($rs = $this->load($id)) && isset($rs[0], $rs[1])) {
            // A cache is available
            $output = $rs[0];
            $return = $rs[1];
        } else {
            // A cache is not available (or not valid for this frontend)
            ob_start();
            ob_implicit_flush(false);
            $return = call_user_func_array($callback, $parameters);
            $output = ob_get_contents();
            ob_end_clean();
            $data = array($output, $return);
            $this->save($data, $id, $tags, $specificLifetime, $priority);
        }

        echo $output;
        return $return;
    }

    /**
     * ZF-9970
     *
     * @deprecated
     */
    private function _makeId($callback, array $args)
    {
        return $this->makeId($callback, $args);
    }

    /**
     * Make a cache id from the function name and parameters
     *
     * @param  callback $callback A valid callback
     * @param  array    $args     Function parameters
     * @throws Zend_Cache_Exception
     * @return string Cache id
     */
    public function makeId($callback, array $args = array())
    {
        if (!is_callable($callback, true, $name)) {
            Zend_Cache::throwException('Invalid callback');
        }

        // functions, methods and classnames are case-insensitive
        $name = strtolower($name);

        // generate a unique id for object callbacks
        if (is_object($callback)) { // Closures & __invoke
            $object = $callback;
        } elseif (isset($callback[0])) { // array($object, 'method')
            $object = $callback[0];
        }
        if (isset($object)) {
            try {
                $tmp = @serialize($callback);
            } catch (Exception $e) {
                Zend_Cache::throwException($e->getMessage());
            }
            if (!$tmp) {
                $lastErr = error_get_last();
                Zend_Cache::throwException("Can't serialize callback object to generate id: {$lastErr['message']}");
            }
            $name.= '__' . $tmp;
        }

        // generate a unique id for arguments
        $argsStr = '';
        if ($args) {
            try {
                $argsStr = @serialize(array_values($args));
            } catch (Exception $e) {
                Zend_Cache::throwException($e->getMessage());
            }
            if (!$argsStr) {
                $lastErr = error_get_last();
                throw Zend_Cache::throwException("Can't serialize arguments to generate id: {$lastErr['message']}");
            }
        }

        return md5($name . $argsStr);
    }

}
