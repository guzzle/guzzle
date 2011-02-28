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
 * @version    $Id: ZendServer.php 20096 2010-01-06 02:05:09Z bkarwin $
 */


/** @see Zend_Cache_Backend_Interface */
// require_once 'Zend/Cache/Backend/Interface.php';

/** @see Zend_Cache_Backend */
// require_once 'Zend/Cache/Backend.php';


/**
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Backend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
abstract class Zend_Cache_Backend_ZendServer extends Zend_Cache_Backend implements Zend_Cache_Backend_Interface
{
    /**
     * Available options
     *
     * =====> (string) namespace :
     * Namespace to be used for chaching operations
     *
     * @var array available options
     */
    protected $_options = array(
        'namespace' => 'zendframework'
    );

    /**
     * Store data
     *
     * @param mixed  $data        Object to store
     * @param string $id          Cache id
     * @param int    $timeToLive  Time to live in seconds
     * @throws Zend_Cache_Exception
     */
    abstract protected function _store($data, $id, $timeToLive);

    /**
     * Fetch data
     *
     * @param string $id          Cache id
     * @throws Zend_Cache_Exception
     */
    abstract protected function _fetch($id);

    /**
     * Unset data
     *
     * @param string $id          Cache id
     */
    abstract protected function _unset($id);

    /**
     * Clear cache
     */
    abstract protected function _clear();

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * @param  string  $id                     cache id
     * @param  boolean $doNotTestCacheValidity if set to true, the cache validity won't be tested
     * @return string cached datas (or false)
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $tmp = $this->_fetch($id);
        if ($tmp !== null) {
            return $tmp;
        }
        return false;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id cache id
     * @return mixed false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     * @throws Zend_Cache_Exception
     */
    public function test($id)
    {
        $tmp = $this->_fetch('internal-metadatas---' . $id);
        if ($tmp !== false) {
            if (!is_array($tmp) || !isset($tmp['mtime'])) {
                Zend_Cache::throwException('Cache metadata for \'' . $id . '\' id is corrupted' );
            }
            return $tmp['mtime'];
        }
        return false;
    }

    /**
     * Compute & return the expire time
     *
     * @return int expire time (unix timestamp)
     */
    private function _expireTime($lifetime)
    {
        if ($lifetime === null) {
            return 9999999999;
        }
        return time() + $lifetime;
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
        $metadatas = array(
            'mtime' => time(),
            'expire' => $this->_expireTime($lifetime),
        );

        if (count($tags) > 0) {
            $this->_log('Zend_Cache_Backend_ZendServer::save() : tags are unsupported by the ZendServer backends');
        }

        return  $this->_store($data, $id, $lifetime) &&
                $this->_store($metadatas, 'internal-metadatas---' . $id, $lifetime);
    }

    /**
     * Remove a cache record
     *
     * @param  string $id cache id
     * @return boolean true if no problem
     */
    public function remove($id)
    {
        $result1 = $this->_unset($id);
        $result2 = $this->_unset('internal-metadatas---' . $id);

        return $result1 && $result2;
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
                $this->_clear();
                return true;
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                $this->_log("Zend_Cache_Backend_ZendServer::clean() : CLEANING_MODE_OLD is unsupported by the Zend Server backends.");
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                $this->_clear();
                $this->_log('Zend_Cache_Backend_ZendServer::clean() : tags are unsupported by the Zend Server backends.');
                break;
            default:
                Zend_Cache::throwException('Invalid mode for clean() method');
                break;
        }
    }
}
