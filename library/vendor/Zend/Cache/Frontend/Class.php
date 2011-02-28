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
 * @version    $Id: Class.php 23051 2010-10-07 17:01:21Z mabe $
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
class Zend_Cache_Frontend_Class extends Zend_Cache_Core
{
    /**
     * Available options
     *
     * ====> (mixed) cached_entity :
     * - if set to a class name, we will cache an abstract class and will use only static calls
     * - if set to an object, we will cache this object methods
     *
     * ====> (boolean) cache_by_default :
     * - if true, method calls will be cached by default
     *
     * ====> (array) cached_methods :
     * - an array of method names which will be cached (even if cache_by_default = false)
     *
     * ====> (array) non_cached_methods :
     * - an array of method names which won't be cached (even if cache_by_default = true)
     *
     * @var array available options
     */
    protected $_specificOptions = array(
        'cached_entity' => null,
        'cache_by_default' => true,
        'cached_methods' => array(),
        'non_cached_methods' => array()
    );

    /**
     * Tags array
     *
     * @var array
     */
    private $_tags = array();

    /**
     * SpecificLifetime value
     *
     * false => no specific life time
     *
     * @var int
     */
    private $_specificLifetime = false;

    /**
     * The cached object or the name of the cached abstract class
     *
     * @var mixed
     */
    private $_cachedEntity = null;

     /**
      * The class name of the cached object or cached abstract class
      *
      * Used to differentiate between different classes with the same method calls.
      *
      * @var string
      */
    private $_cachedEntityLabel = '';

    /**
     * Priority (used by some particular backends)
     *
     * @var int
     */
    private $_priority = 8;

    /**
     * Constructor
     *
     * @param  array $options Associative array of options
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function __construct(array $options = array())
    {
        while (list($name, $value) = each($options)) {
            $this->setOption($name, $value);
        }
        if ($this->_specificOptions['cached_entity'] === null) {
            Zend_Cache::throwException('cached_entity must be set !');
        }
        $this->setCachedEntity($this->_specificOptions['cached_entity']);
        $this->setOption('automatic_serialization', true);
    }

    /**
     * Set a specific life time
     *
     * @param  int $specificLifetime
     * @return void
     */
    public function setSpecificLifetime($specificLifetime = false)
    {
        $this->_specificLifetime = $specificLifetime;
    }

    /**
     * Set the priority (used by some particular backends)
     *
     * @param int $priority integer between 0 (very low priority) and 10 (maximum priority)
     */
    public function setPriority($priority)
    {
        $this->_priority = $priority;
    }

    /**
     * Public frontend to set an option
     *
     * Just a wrapper to get a specific behaviour for cached_entity
     *
     * @param  string $name  Name of the option
     * @param  mixed  $value Value of the option
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setOption($name, $value)
    {
        if ($name == 'cached_entity') {
            $this->setCachedEntity($value);
        } else {
            parent::setOption($name, $value);
        }
    }

    /**
     * Specific method to set the cachedEntity
     *
     * if set to a class name, we will cache an abstract class and will use only static calls
     * if set to an object, we will cache this object methods
     *
     * @param mixed $cachedEntity
     */
    public function setCachedEntity($cachedEntity)
    {
        if (!is_string($cachedEntity) && !is_object($cachedEntity)) {
            Zend_Cache::throwException('cached_entity must be an object or a class name');
        }
        $this->_cachedEntity = $cachedEntity;
        $this->_specificOptions['cached_entity'] = $cachedEntity;
        if (is_string($this->_cachedEntity)){
            $this->_cachedEntityLabel = $this->_cachedEntity;
        } else {
            $ro = new ReflectionObject($this->_cachedEntity);
            $this->_cachedEntityLabel = $ro->getName();
        }
    }

    /**
     * Set the cache array
     *
     * @param  array $tags
     * @return void
     */
    public function setTagsArray($tags = array())
    {
        $this->_tags = $tags;
    }

    /**
     * Main method : call the specified method or get the result from cache
     *
     * @param  string $name       Method name
     * @param  array  $parameters Method parameters
     * @return mixed Result
     */
    public function __call($name, $parameters)
    {
        $cacheBool1 = $this->_specificOptions['cache_by_default'];
        $cacheBool2 = in_array($name, $this->_specificOptions['cached_methods']);
        $cacheBool3 = in_array($name, $this->_specificOptions['non_cached_methods']);
        $cache = (($cacheBool1 || $cacheBool2) && (!$cacheBool3));
        if (!$cache) {
            // We do not have not cache
            return call_user_func_array(array($this->_cachedEntity, $name), $parameters);
        }

        $id = $this->_makeId($name, $parameters);
        if ( ($rs = $this->load($id)) && isset($rs[0], $rs[1]) ) {
            // A cache is available
            $output = $rs[0];
            $return = $rs[1];
        } else {
            // A cache is not available (or not valid for this frontend)
            ob_start();
            ob_implicit_flush(false);

            try {
                $return = call_user_func_array(array($this->_cachedEntity, $name), $parameters);
                $output = ob_get_clean();
                $data = array($output, $return);
                $this->save($data, $id, $this->_tags, $this->_specificLifetime, $this->_priority);
            } catch (Exception $e) {
                ob_end_clean();
                throw $e;
            }
        }

        echo $output;
        return $return;
    }

    /**
     * ZF-9970
     *
     * @deprecated
     */
    private function _makeId($name, $args)
    {
        return $this->makeId($name, $args);
    }

    /**
     * Make a cache id from the method name and parameters
     *
     * @param  string $name Method name
     * @param  array  $args Method parameters
     * @return string Cache id
     */
    public function makeId($name, array $args = array())
    {
        return md5($this->_cachedEntityLabel . '__' . $name . '__' . serialize($args));
    }

}
