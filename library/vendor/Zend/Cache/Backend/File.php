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
 * @version    $Id: File.php 21636 2010-03-24 17:10:23Z mabe $
 */

/**
 * @see Zend_Cache_Backend_Interface
 */
// require_once 'Zend/Cache/Backend/ExtendedInterface.php';

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
class Zend_Cache_Backend_File extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    /**
     * Available options
     *
     * =====> (string) cache_dir :
     * - Directory where to put the cache files
     *
     * =====> (boolean) file_locking :
     * - Enable / disable file_locking
     * - Can avoid cache corruption under bad circumstances but it doesn't work on multithread
     * webservers and on NFS filesystems for example
     *
     * =====> (boolean) read_control :
     * - Enable / disable read control
     * - If enabled, a control key is embeded in cache file and this key is compared with the one
     * calculated after the reading.
     *
     * =====> (string) read_control_type :
     * - Type of read control (only if read control is enabled). Available values are :
     *   'md5' for a md5 hash control (best but slowest)
     *   'crc32' for a crc32 hash control (lightly less safe but faster, better choice)
     *   'adler32' for an adler32 hash control (excellent choice too, faster than crc32)
     *   'strlen' for a length only test (fastest)
     *
     * =====> (int) hashed_directory_level :
     * - Hashed directory level
     * - Set the hashed directory structure level. 0 means "no hashed directory
     * structure", 1 means "one level of directory", 2 means "two levels"...
     * This option can speed up the cache only when you have many thousands of
     * cache file. Only specific benchs can help you to choose the perfect value
     * for you. Maybe, 1 or 2 is a good start.
     *
     * =====> (int) hashed_directory_umask :
     * - Umask for hashed directory structure
     *
     * =====> (string) file_name_prefix :
     * - prefix for cache files
     * - be really carefull with this option because a too generic value in a system cache dir
     *   (like /tmp) can cause disasters when cleaning the cache
     *
     * =====> (int) cache_file_umask :
     * - Umask for cache files
     *
     * =====> (int) metatadatas_array_max_size :
     * - max size for the metadatas array (don't change this value unless you
     *   know what you are doing)
     *
     * @var array available options
     */
    protected $_options = array(
        'cache_dir' => null,
        'file_locking' => true,
        'read_control' => true,
        'read_control_type' => 'crc32',
        'hashed_directory_level' => 0,
        'hashed_directory_umask' => 0700,
        'file_name_prefix' => 'zend_cache',
        'cache_file_umask' => 0600,
        'metadatas_array_max_size' => 100
    );

    /**
     * Array of metadatas (each item is an associative array)
     *
     * @var array
     */
    protected $_metadatasArray = array();


    /**
     * Constructor
     *
     * @param  array $options associative array of options
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function __construct(array $options = array())
    {
        parent::__construct($options);
        if ($this->_options['cache_dir'] !== null) { // particular case for this option
            $this->setCacheDir($this->_options['cache_dir']);
        } else {
            $this->setCacheDir(self::getTmpDir() . DIRECTORY_SEPARATOR, false);
        }
        if (isset($this->_options['file_name_prefix'])) { // particular case for this option
            if (!preg_match('~^[a-zA-Z0-9_]+$~D', $this->_options['file_name_prefix'])) {
                Zend_Cache::throwException('Invalid file_name_prefix : must use only [a-zA-Z0-9_]');
            }
        }
        if ($this->_options['metadatas_array_max_size'] < 10) {
            Zend_Cache::throwException('Invalid metadatas_array_max_size, must be > 10');
        }
        if (isset($options['hashed_directory_umask']) && is_string($options['hashed_directory_umask'])) {
            // See #ZF-4422
            $this->_options['hashed_directory_umask'] = octdec($this->_options['hashed_directory_umask']);
        }
        if (isset($options['cache_file_umask']) && is_string($options['cache_file_umask'])) {
            // See #ZF-4422
            $this->_options['cache_file_umask'] = octdec($this->_options['cache_file_umask']);
        }
    }

    /**
     * Set the cache_dir (particular case of setOption() method)
     *
     * @param  string  $value
     * @param  boolean $trailingSeparator If true, add a trailing separator is necessary
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setCacheDir($value, $trailingSeparator = true)
    {
        if (!is_dir($value)) {
            Zend_Cache::throwException('cache_dir must be a directory');
        }
        if (!is_writable($value)) {
            Zend_Cache::throwException('cache_dir is not writable');
        }
        if ($trailingSeparator) {
            // add a trailing DIRECTORY_SEPARATOR if necessary
            $value = rtrim(realpath($value), '\\/') . DIRECTORY_SEPARATOR;
        }
        $this->_options['cache_dir'] = $value;
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * @param string $id cache id
     * @param boolean $doNotTestCacheValidity if set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        if (!($this->_test($id, $doNotTestCacheValidity))) {
            // The cache is not hit !
            return false;
        }
        $metadatas = $this->_getMetadatas($id);
        $file = $this->_file($id);
        $data = $this->_fileGetContents($file);
        if ($this->_options['read_control']) {
            $hashData = $this->_hash($data, $this->_options['read_control_type']);
            $hashControl = $metadatas['hash'];
            if ($hashData != $hashControl) {
                // Problem detected by the read control !
                $this->_log('Zend_Cache_Backend_File::load() / read_control : stored hash and computed hash do not match');
                $this->remove($id);
                return false;
            }
        }
        return $data;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param string $id cache id
     * @return mixed false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        clearstatcache();
        return $this->_test($id, false);
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean true if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        clearstatcache();
        $file = $this->_file($id);
        $path = $this->_path($id);
        if ($this->_options['hashed_directory_level'] > 0) {
            if (!is_writable($path)) {
                // maybe, we just have to build the directory structure
                $this->_recursiveMkdirAndChmod($id);
            }
            if (!is_writable($path)) {
                return false;
            }
        }
        if ($this->_options['read_control']) {
            $hash = $this->_hash($data, $this->_options['read_control_type']);
        } else {
            $hash = '';
        }
        $metadatas = array(
            'hash' => $hash,
            'mtime' => time(),
            'expire' => $this->_expireTime($this->getLifetime($specificLifetime)),
            'tags' => $tags
        );
        $res = $this->_setMetadatas($id, $metadatas);
        if (!$res) {
            $this->_log('Zend_Cache_Backend_File::save() / error on saving metadata');
            return false;
        }
        $res = $this->_filePutContents($file, $data);
        return $res;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id cache id
     * @return boolean true if no problem
     */
    public function remove($id)
    {
        $file = $this->_file($id);
        $boolRemove   = $this->_remove($file);
        $boolMetadata = $this->_delMetadatas($id);
        return $boolMetadata && $boolRemove;
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => remove too old cache entries ($tags is not used)
     * 'matchingTag'    => remove cache entries matching all given tags
     *                     ($tags can be an array of strings or a single string)
     * 'notMatchingTag' => remove cache entries not matching one of the given tags
     *                     ($tags can be an array of strings or a single string)
     * 'matchingAnyTag' => remove cache entries matching any given tags
     *                     ($tags can be an array of strings or a single string)
     *
     * @param string $mode clean mode
     * @param tags array $tags array of tags
     * @return boolean true if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        // We use this protected method to hide the recursive stuff
        clearstatcache();
        return $this->_clean($this->_options['cache_dir'], $mode, $tags);
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        return $this->_get($this->_options['cache_dir'], 'ids', array());
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return $this->_get($this->_options['cache_dir'], 'tags', array());
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        return $this->_get($this->_options['cache_dir'], 'matching', $tags);
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        return $this->_get($this->_options['cache_dir'], 'notMatching', $tags);
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        return $this->_get($this->_options['cache_dir'], 'matchingAny', $tags);
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        $free = disk_free_space($this->_options['cache_dir']);
        $total = disk_total_space($this->_options['cache_dir']);
        if ($total == 0) {
            Zend_Cache::throwException('can\'t get disk_total_space');
        } else {
            if ($free >= $total) {
                return 100;
            }
            return ((int) (100. * ($total - $free) / $total));
        }
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        $metadatas = $this->_getMetadatas($id);
        if (!$metadatas) {
            return false;
        }
        if (time() > $metadatas['expire']) {
            return false;
        }
        return array(
            'expire' => $metadatas['expire'],
            'tags' => $metadatas['tags'],
            'mtime' => $metadatas['mtime']
        );
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $metadatas = $this->_getMetadatas($id);
        if (!$metadatas) {
            return false;
        }
        if (time() > $metadatas['expire']) {
            return false;
        }
        $newMetadatas = array(
            'hash' => $metadatas['hash'],
            'mtime' => time(),
            'expire' => $metadatas['expire'] + $extraLifetime,
            'tags' => $metadatas['tags']
        );
        $res = $this->_setMetadatas($id, $newMetadatas);
        if (!$res) {
            return false;
        }
        return true;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => true,
            'tags' => true,
            'expired_read' => true,
            'priority' => false,
            'infinite_lifetime' => true,
            'get_list' => true
        );
    }

    /**
     * PUBLIC METHOD FOR UNIT TESTING ONLY !
     *
     * Force a cache record to expire
     *
     * @param string $id cache id
     */
    public function ___expire($id)
    {
        $metadatas = $this->_getMetadatas($id);
        if ($metadatas) {
            $metadatas['expire'] = 1;
            $this->_setMetadatas($id, $metadatas);
        }
    }

    /**
     * Get a metadatas record
     *
     * @param  string $id  Cache id
     * @return array|false Associative array of metadatas
     */
    protected function _getMetadatas($id)
    {
        if (isset($this->_metadatasArray[$id])) {
            return $this->_metadatasArray[$id];
        } else {
            $metadatas = $this->_loadMetadatas($id);
            if (!$metadatas) {
                return false;
            }
            $this->_setMetadatas($id, $metadatas, false);
            return $metadatas;
        }
    }

    /**
     * Set a metadatas record
     *
     * @param  string $id        Cache id
     * @param  array  $metadatas Associative array of metadatas
     * @param  boolean $save     optional pass false to disable saving to file
     * @return boolean True if no problem
     */
    protected function _setMetadatas($id, $metadatas, $save = true)
    {
        if (count($this->_metadatasArray) >= $this->_options['metadatas_array_max_size']) {
            $n = (int) ($this->_options['metadatas_array_max_size'] / 10);
            $this->_metadatasArray = array_slice($this->_metadatasArray, $n);
        }
        if ($save) {
            $result = $this->_saveMetadatas($id, $metadatas);
            if (!$result) {
                return false;
            }
        }
        $this->_metadatasArray[$id] = $metadatas;
        return true;
    }

    /**
     * Drop a metadata record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    protected function _delMetadatas($id)
    {
        if (isset($this->_metadatasArray[$id])) {
            unset($this->_metadatasArray[$id]);
        }
        $file = $this->_metadatasFile($id);
        return $this->_remove($file);
    }

    /**
     * Clear the metadatas array
     *
     * @return void
     */
    protected function _cleanMetadatas()
    {
        $this->_metadatasArray = array();
    }

    /**
     * Load metadatas from disk
     *
     * @param  string $id Cache id
     * @return array|false Metadatas associative array
     */
    protected function _loadMetadatas($id)
    {
        $file = $this->_metadatasFile($id);
        $result = $this->_fileGetContents($file);
        if (!$result) {
            return false;
        }
        $tmp = @unserialize($result);
        return $tmp;
    }

    /**
     * Save metadatas to disk
     *
     * @param  string $id        Cache id
     * @param  array  $metadatas Associative array
     * @return boolean True if no problem
     */
    protected function _saveMetadatas($id, $metadatas)
    {
        $file = $this->_metadatasFile($id);
        $result = $this->_filePutContents($file, serialize($metadatas));
        if (!$result) {
            return false;
        }
        return true;
    }

    /**
     * Make and return a file name (with path) for metadatas
     *
     * @param  string $id Cache id
     * @return string Metadatas file name (with path)
     */
    protected function _metadatasFile($id)
    {
        $path = $this->_path($id);
        $fileName = $this->_idToFileName('internal-metadatas---' . $id);
        return $path . $fileName;
    }

    /**
     * Check if the given filename is a metadatas one
     *
     * @param  string $fileName File name
     * @return boolean True if it's a metadatas one
     */
    protected function _isMetadatasFile($fileName)
    {
        $id = $this->_fileNameToId($fileName);
        if (substr($id, 0, 21) == 'internal-metadatas---') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Remove a file
     *
     * If we can't remove the file (because of locks or any problem), we will touch
     * the file to invalidate it
     *
     * @param  string $file Complete file path
     * @return boolean True if ok
     */
    protected function _remove($file)
    {
        if (!is_file($file)) {
            return false;
        }
        if (!@unlink($file)) {
            # we can't remove the file (because of locks or any problem)
            $this->_log("Zend_Cache_Backend_File::_remove() : we can't remove $file");
            return false;
        }
        return true;
    }

    /**
     * Clean some cache records (protected method used for recursive stuff)
     *
     * Available modes are :
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     *
     * @param  string $dir  Directory to clean
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    protected function _clean($dir, $mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if (!is_dir($dir)) {
            return false;
        }
        $result = true;
        $prefix = $this->_options['file_name_prefix'];
        $glob = @glob($dir . $prefix . '--*');
        if ($glob === false) {
            // On some systems it is impossible to distinguish between empty match and an error.
            return true;
        }
        foreach ($glob as $file)  {
            if (is_file($file)) {
                $fileName = basename($file);
                if ($this->_isMetadatasFile($fileName)) {
                    // in CLEANING_MODE_ALL, we drop anything, even remainings old metadatas files
                    if ($mode != Zend_Cache::CLEANING_MODE_ALL) {
                        continue;
                    }
                }
                $id = $this->_fileNameToId($fileName);
                $metadatas = $this->_getMetadatas($id);
                if ($metadatas === FALSE) {
                    $metadatas = array('expire' => 1, 'tags' => array());
                }
                switch ($mode) {
                    case Zend_Cache::CLEANING_MODE_ALL:
                        $res = $this->remove($id);
                        if (!$res) {
                            // in this case only, we accept a problem with the metadatas file drop
                            $res = $this->_remove($file);
                        }
                        $result = $result && $res;
                        break;
                    case Zend_Cache::CLEANING_MODE_OLD:
                        if (time() > $metadatas['expire']) {
                            $result = $this->remove($id) && $result;
                        }
                        break;
                    case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                        $matching = true;
                        foreach ($tags as $tag) {
                            if (!in_array($tag, $metadatas['tags'])) {
                                $matching = false;
                                break;
                            }
                        }
                        if ($matching) {
                            $result = $this->remove($id) && $result;
                        }
                        break;
                    case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                        $matching = false;
                        foreach ($tags as $tag) {
                            if (in_array($tag, $metadatas['tags'])) {
                                $matching = true;
                                break;
                            }
                        }
                        if (!$matching) {
                            $result = $this->remove($id) && $result;
                        }
                        break;
                    case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                        $matching = false;
                        foreach ($tags as $tag) {
                            if (in_array($tag, $metadatas['tags'])) {
                                $matching = true;
                                break;
                            }
                        }
                        if ($matching) {
                            $result = $this->remove($id) && $result;
                        }
                        break;
                    default:
                        Zend_Cache::throwException('Invalid mode for clean() method');
                        break;
                }
            }
            if ((is_dir($file)) and ($this->_options['hashed_directory_level']>0)) {
                // Recursive call
                $result = $this->_clean($file . DIRECTORY_SEPARATOR, $mode, $tags) && $result;
                if ($mode=='all') {
                    // if mode=='all', we try to drop the structure too
                    @rmdir($file);
                }
            }
        }
        return $result;
    }

    protected function _get($dir, $mode, $tags = array())
    {
        if (!is_dir($dir)) {
            return false;
        }
        $result = array();
        $prefix = $this->_options['file_name_prefix'];
        $glob = @glob($dir . $prefix . '--*');
        if ($glob === false) {
            // On some systems it is impossible to distinguish between empty match and an error.
            return array();
        }
        foreach ($glob as $file)  {
            if (is_file($file)) {
                $fileName = basename($file);
                $id = $this->_fileNameToId($fileName);
                $metadatas = $this->_getMetadatas($id);
                if ($metadatas === FALSE) {
                    continue;
                }
                if (time() > $metadatas['expire']) {
                    continue;
                }
                switch ($mode) {
                    case 'ids':
                        $result[] = $id;
                        break;
                    case 'tags':
                        $result = array_unique(array_merge($result, $metadatas['tags']));
                        break;
                    case 'matching':
                        $matching = true;
                        foreach ($tags as $tag) {
                            if (!in_array($tag, $metadatas['tags'])) {
                                $matching = false;
                                break;
                            }
                        }
                        if ($matching) {
                            $result[] = $id;
                        }
                        break;
                    case 'notMatching':
                        $matching = false;
                        foreach ($tags as $tag) {
                            if (in_array($tag, $metadatas['tags'])) {
                                $matching = true;
                                break;
                            }
                        }
                        if (!$matching) {
                            $result[] = $id;
                        }
                        break;
                    case 'matchingAny':
                        $matching = false;
                        foreach ($tags as $tag) {
                            if (in_array($tag, $metadatas['tags'])) {
                                $matching = true;
                                break;
                            }
                        }
                        if ($matching) {
                            $result[] = $id;
                        }
                        break;
                    default:
                        Zend_Cache::throwException('Invalid mode for _get() method');
                        break;
                }
            }
            if ((is_dir($file)) and ($this->_options['hashed_directory_level']>0)) {
                // Recursive call
                $recursiveRs =  $this->_get($file . DIRECTORY_SEPARATOR, $mode, $tags);
                if ($recursiveRs === false) {
                    $this->_log('Zend_Cache_Backend_File::_get() / recursive call : can\'t list entries of "'.$file.'"');
                } else {
                    $result = array_unique(array_merge($result, $recursiveRs));
                }
            }
        }
        return array_unique($result);
    }

    /**
     * Compute & return the expire time
     *
     * @return int expire time (unix timestamp)
     */
    protected function _expireTime($lifetime)
    {
        if ($lifetime === null) {
            return 9999999999;
        }
        return time() + $lifetime;
    }

    /**
     * Make a control key with the string containing datas
     *
     * @param  string $data        Data
     * @param  string $controlType Type of control 'md5', 'crc32' or 'strlen'
     * @throws Zend_Cache_Exception
     * @return string Control key
     */
    protected function _hash($data, $controlType)
    {
        switch ($controlType) {
        case 'md5':
            return md5($data);
        case 'crc32':
            return crc32($data);
        case 'strlen':
            return strlen($data);
        case 'adler32':
            return hash('adler32', $data);
        default:
            Zend_Cache::throwException("Incorrect hash function : $controlType");
        }
    }

    /**
     * Transform a cache id into a file name and return it
     *
     * @param  string $id Cache id
     * @return string File name
     */
    protected function _idToFileName($id)
    {
        $prefix = $this->_options['file_name_prefix'];
        $result = $prefix . '---' . $id;
        return $result;
    }

    /**
     * Make and return a file name (with path)
     *
     * @param  string $id Cache id
     * @return string File name (with path)
     */
    protected function _file($id)
    {
        $path = $this->_path($id);
        $fileName = $this->_idToFileName($id);
        return $path . $fileName;
    }

    /**
     * Return the complete directory path of a filename (including hashedDirectoryStructure)
     *
     * @param  string $id Cache id
     * @param  boolean $parts if true, returns array of directory parts instead of single string
     * @return string Complete directory path
     */
    protected function _path($id, $parts = false)
    {
        $partsArray = array();
        $root = $this->_options['cache_dir'];
        $prefix = $this->_options['file_name_prefix'];
        if ($this->_options['hashed_directory_level']>0) {
            $hash = hash('adler32', $id);
            for ($i=0 ; $i < $this->_options['hashed_directory_level'] ; $i++) {
                $root = $root . $prefix . '--' . substr($hash, 0, $i + 1) . DIRECTORY_SEPARATOR;
                $partsArray[] = $root;
            }
        }
        if ($parts) {
            return $partsArray;
        } else {
            return $root;
        }
    }

    /**
     * Make the directory strucuture for the given id
     *
     * @param string $id cache id
     * @return boolean true
     */
    protected function _recursiveMkdirAndChmod($id)
    {
        if ($this->_options['hashed_directory_level'] <=0) {
            return true;
        }
        $partsArray = $this->_path($id, true);
        foreach ($partsArray as $part) {
            if (!is_dir($part)) {
                @mkdir($part, $this->_options['hashed_directory_umask']);
                @chmod($part, $this->_options['hashed_directory_umask']); // see #ZF-320 (this line is required in some configurations)
            }
        }
        return true;
    }

    /**
     * Test if the given cache id is available (and still valid as a cache record)
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return boolean|mixed false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    protected function _test($id, $doNotTestCacheValidity)
    {
        $metadatas = $this->_getMetadatas($id);
        if (!$metadatas) {
            return false;
        }
        if ($doNotTestCacheValidity || (time() <= $metadatas['expire'])) {
            return $metadatas['mtime'];
        }
        return false;
    }

    /**
     * Return the file content of the given file
     *
     * @param  string $file File complete path
     * @return string File content (or false if problem)
     */
    protected function _fileGetContents($file)
    {
        $result = false;
        if (!is_file($file)) {
            return false;
        }
        $f = @fopen($file, 'rb');
        if ($f) {
            if ($this->_options['file_locking']) @flock($f, LOCK_SH);
            $result = stream_get_contents($f);
            if ($this->_options['file_locking']) @flock($f, LOCK_UN);
            @fclose($f);
        }
        return $result;
    }

    /**
     * Put the given string into the given file
     *
     * @param  string $file   File complete path
     * @param  string $string String to put in file
     * @return boolean true if no problem
     */
    protected function _filePutContents($file, $string)
    {
        $result = false;
        $f = @fopen($file, 'ab+');
        if ($f) {
            if ($this->_options['file_locking']) @flock($f, LOCK_EX);
            fseek($f, 0);
            ftruncate($f, 0);
            $tmp = @fwrite($f, $string);
            if (!($tmp === FALSE)) {
                $result = true;
            }
            @fclose($f);
        }
        @chmod($file, $this->_options['cache_file_umask']);
        return $result;
    }

    /**
     * Transform a file name into cache id and return it
     *
     * @param  string $fileName File name
     * @return string Cache id
     */
    protected function _fileNameToId($fileName)
    {
        $prefix = $this->_options['file_name_prefix'];
        return preg_replace('~^' . $prefix . '---(.*)$~', '$1', $fileName);
    }

}
