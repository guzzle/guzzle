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
 * @subpackage Zend_Cache_Backend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Xcache.php 23345 2010-11-15 16:31:14Z mabe $
 */


/**
 * @see Zend_Cache_Backend_Interface
 */
// require_once 'Zend/Cache/Backend/Interface.php';

/**
 * @see Zend_Cache_Backend
 */
// require_once 'Zend/Cache/Backend.php';


/**
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Backend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Cache_Backend_Xcache extends Zend_Cache_Backend implements Zend_Cache_Backend_Interface
{

    /**
     * Log message
     */
    const TAGS_UNSUPPORTED_BY_CLEAN_OF_XCACHE_BACKEND = 'Zend_Cache_Backend_Xcache::clean() : tags are unsupported by the Xcache backend';
    const TAGS_UNSUPPORTED_BY_SAVE_OF_XCACHE_BACKEND =  'Zend_Cache_Backend_Xcache::save() : tags are unsupported by the Xcache backend';

    /**
     * Available options
     *
     * =====> (string) user :
     * xcache.admin.user (necessary for the clean() method)
     *
     * =====> (string) password :
     * xcache.admin.pass (clear, not MD5) (necessary for the clean() method)
     *
     * @var array available options
     */
    protected $_options = array(
        'user' => null,
        'password' => null
    );

    /**
     * Constructor
     *
     * @param  array $options associative array of options
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function __construct(array $options = array())
    {
        if (!extension_loaded('xcache')) {
            Zend_Cache::throwException('The xcache extension must be loaded for using this backend !');
        }
        parent::__construct($options);
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * WARNING $doNotTestCacheValidity=true is unsupported by the Xcache backend
     *
     * @param  string  $id                     cache id
     * @param  boolean $doNotTestCacheValidity if set to true, the cache validity won't be tested
     * @return string cached datas (or false)
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        if ($doNotTestCacheValidity) {
            $this->_log("Zend_Cache_Backend_Xcache::load() : \$doNotTestCacheValidity=true is unsupported by the Xcache backend");
        }
        $tmp = xcache_get($id);
        if (is_array($tmp)) {
            return $tmp[0];
        }
        return false;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id cache id
     * @return mixed false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        if (xcache_isset($id)) {
            $tmp = xcache_get($id);
            if (is_array($tmp)) {
                return $tmp[1];
            }
        }
        return false;
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param string $data datas to cache
     * @param string $id cache id
     * @param array $tags array of strings, the cache record will be tagged by each string entry
     * @param int $specificLifetime if != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean true if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $lifetime = $this->getLifetime($specificLifetime);
        $result = xcache_set($id, array($data, time()), $lifetime);
        if (count($tags) > 0) {
            $this->_log(self::TAGS_UNSUPPORTED_BY_SAVE_OF_XCACHE_BACKEND);
        }
        return $result;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id cache id
     * @return boolean true if no problem
     */
    public function remove($id)
    {
        return xcache_unset($id);
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => unsupported
     * 'matchingTag'    => unsupported
     * 'notMatchingTag' => unsupported
     * 'matchingAnyTag' => unsupported
     *
     * @param  string $mode clean mode
     * @param  array  $tags array of tags
     * @throws Zend_Cache_Exception
     * @return boolean true if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                // Necessary because xcache_clear_cache() need basic authentification
                $backup = array();
                if (isset($_SERVER['PHP_AUTH_USER'])) {
                    $backup['PHP_AUTH_USER'] = $_SERVER['PHP_AUTH_USER'];
                }
                if (isset($_SERVER['PHP_AUTH_PW'])) {
                    $backup['PHP_AUTH_PW'] = $_SERVER['PHP_AUTH_PW'];
                }
                if ($this->_options['user']) {
                    $_SERVER['PHP_AUTH_USER'] = $this->_options['user'];
                }
                if ($this->_options['password']) {
                    $_SERVER['PHP_AUTH_PW'] = $this->_options['password'];
                }

                $cnt = xcache_count(XC_TYPE_VAR);
                for ($i=0; $i < $cnt; $i++) {
                    xcache_clear_cache(XC_TYPE_VAR, $i);
                }

                if (isset($backup['PHP_AUTH_USER'])) {
                    $_SERVER['PHP_AUTH_USER'] = $backup['PHP_AUTH_USER'];
                    $_SERVER['PHP_AUTH_PW'] = $backup['PHP_AUTH_PW'];
                }
                return true;
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                $this->_log("Zend_Cache_Backend_Xcache::clean() : CLEANING_MODE_OLD is unsupported by the Xcache backend");
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                $this->_log(self::TAGS_UNSUPPORTED_BY_CLEAN_OF_XCACHE_BACKEND);
                break;
            default:
                Zend_Cache::throwException('Invalid mode for clean() method');
                break;
        }
    }

    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return false;
    }

}
