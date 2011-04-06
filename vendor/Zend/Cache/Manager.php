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
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Manager.php 22727 2010-07-30 12:36:00Z mabe $
 */

/** @see Zend_Cache_Exception */
// require_once 'Zend/Cache/Exception.php';

/** @see Zend_Cache */
// require_once 'Zend/Cache.php';

/**
 * @category   Zend
 * @package    Zend_Cache
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Cache_Manager
{
    /**
     * Constant holding reserved name for default Page Cache
     */
    const PAGECACHE = 'page';

    /**
     * Constant holding reserved name for default Page Tag Cache
     */
    const PAGETAGCACHE = 'pagetag';

    /**
     * Array of caches stored by the Cache Manager instance
     *
     * @var array
     */
    protected $_caches = array();

    /**
     * Array of ready made configuration templates for lazy
     * loading caches.
     *
     * @var array
     */
    protected $_optionTemplates = array(
        // Simple Common Default
        'default' => array(
            'frontend' => array(
                'name'    => 'Core',
                'options' => array(
                    'automatic_serialization' => true,
                ),
            ),
            'backend' => array(
                'name'    => 'File',
                'options' => array(
                    // use system temp dir by default of file backend
                    // 'cache_dir' => '../cache',
                ),
            ),
        ),

        // Static Page HTML Cache
        'page' => array(
            'frontend' => array(
                'name'    => 'Capture',
                'options' => array(
                    'ignore_user_abort' => true,
                ),
            ),
            'backend' => array(
                'name'    => 'Static',
                'options' => array(
                    'public_dir' => '../public',
                ),
            ),
        ),

        // Tag Cache
        'pagetag' => array(
            'frontend' => array(
                'name'    => 'Core',
                'options' => array(
                    'automatic_serialization' => true,
                    'lifetime' => null
                ),
            ),
            'backend' => array(
                'name'    => 'File',
                'options' => array(
                    // use system temp dir by default of file backend
                    // 'cache_dir' => '../cache',
                    // use default umask of file backend
                    // 'cache_file_umask' => 0644
                ),
            ),
        ),
    );

    /**
     * Set a new cache for the Cache Manager to contain
     *
     * @param  string $name
     * @param  Zend_Cache_Core $cache
     * @return Zend_Cache_Manager
     */
    public function setCache($name, Zend_Cache_Core $cache)
    {
        $this->_caches[$name] = $cache;
        return $this;
    }

    /**
     * Check if the Cache Manager contains the named cache object, or a named
     * configuration template to lazy load the cache object
     *
     * @param string $name
     * @return bool
     */
    public function hasCache($name)
    {
        if (isset($this->_caches[$name])
            || $this->hasCacheTemplate($name)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Fetch the named cache object, or instantiate and return a cache object
     * using a named configuration template
     *
     * @param  string $name
     * @return Zend_Cache_Core
     */
    public function getCache($name)
    {
        if (isset($this->_caches[$name])) {
            return $this->_caches[$name];
        }
        if (isset($this->_optionTemplates[$name])) {
            if ($name == self::PAGECACHE
                && (!isset($this->_optionTemplates[$name]['backend']['options']['tag_cache'])
                || !$this->_optionTemplates[$name]['backend']['options']['tag_cache'] instanceof Zend_Cache_Core)
            ) {
                $this->_optionTemplates[$name]['backend']['options']['tag_cache']
                    = $this->getCache(self::PAGETAGCACHE);
            }

            $this->_caches[$name] = Zend_Cache::factory(
                $this->_optionTemplates[$name]['frontend']['name'],
                $this->_optionTemplates[$name]['backend']['name'],
                isset($this->_optionTemplates[$name]['frontend']['options']) ? $this->_optionTemplates[$name]['frontend']['options'] : array(),
                isset($this->_optionTemplates[$name]['backend']['options']) ? $this->_optionTemplates[$name]['backend']['options'] : array(),
                isset($this->_optionTemplates[$name]['frontend']['customFrontendNaming']) ? $this->_optionTemplates[$name]['frontend']['customFrontendNaming'] : false,
                isset($this->_optionTemplates[$name]['backend']['customBackendNaming']) ? $this->_optionTemplates[$name]['backend']['customBackendNaming'] : false,
                isset($this->_optionTemplates[$name]['frontendBackendAutoload']) ? $this->_optionTemplates[$name]['frontendBackendAutoload'] : false
            );

            return $this->_caches[$name];
        }
    }

    /**
     * Fetch all available caches
     *
     * @return array An array of all available caches with it's names as key
     */
    public function getCaches()
    {
        $caches = $this->_caches;
        foreach ($this->_optionTemplates as $name => $tmp) {
            if (!isset($caches[$name])) {
                $caches[$name] = $this->getCache($name);
            }
        }
        return $caches;
    }

    /**
     * Set a named configuration template from which a cache object can later
     * be lazy loaded
     *
     * @param  string $name
     * @param  array $options
     * @return Zend_Cache_Manager
     */
    public function setCacheTemplate($name, $options)
    {
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        } elseif (!is_array($options)) {
            // require_once 'Zend/Cache/Exception.php';
            throw new Zend_Cache_Exception('Options passed must be in'
                . ' an associative array or instance of Zend_Config');
        }
        $this->_optionTemplates[$name] = $options;
        return $this;
    }

    /**
     * Check if the named configuration template
     *
     * @param  string $name
     * @return bool
     */
    public function hasCacheTemplate($name)
    {
        if (isset($this->_optionTemplates[$name])) {
            return true;
        }
        return false;
    }

    /**
     * Get the named configuration template
     *
     * @param  string $name
     * @return array
     */
    public function getCacheTemplate($name)
    {
        if (isset($this->_optionTemplates[$name])) {
            return $this->_optionTemplates[$name];
        }
    }

    /**
     * Pass an array containing changes to be applied to a named
     * configuration
     * template
     *
     * @param  string $name
     * @param  array $options
     * @return Zend_Cache_Manager
     * @throws Zend_Cache_Exception for invalid options format or if option templates do not have $name
     */
    public function setTemplateOptions($name, $options)
    {
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        } elseif (!is_array($options)) {
            // require_once 'Zend/Cache/Exception.php';
            throw new Zend_Cache_Exception('Options passed must be in'
                . ' an associative array or instance of Zend_Config');
        }
        if (!isset($this->_optionTemplates[$name])) {
            throw new Zend_Cache_Exception('A cache configuration template'
                . 'does not exist with the name "' . $name . '"');
        }
        $this->_optionTemplates[$name]
            = $this->_mergeOptions($this->_optionTemplates[$name], $options);
        return $this;
    }

    /**
     * Simple method to merge two configuration arrays
     *
     * @param  array $current
     * @param  array $options
     * @return array
     */
    protected function _mergeOptions(array $current, array $options)
    {
        if (isset($options['frontend']['name'])) {
            $current['frontend']['name'] = $options['frontend']['name'];
        }
        if (isset($options['backend']['name'])) {
            $current['backend']['name'] = $options['backend']['name'];
        }
        if (isset($options['frontend']['options'])) {
            foreach ($options['frontend']['options'] as $key=>$value) {
                $current['frontend']['options'][$key] = $value;
            }
        }
        if (isset($options['backend']['options'])) {
            foreach ($options['backend']['options'] as $key=>$value) {
                $current['backend']['options'][$key] = $value;
            }
        }
        return $current;
    }
}
