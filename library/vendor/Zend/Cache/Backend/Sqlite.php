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
 * @version    $Id: Sqlite.php 20096 2010-01-06 02:05:09Z bkarwin $
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
class Zend_Cache_Backend_Sqlite extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    /**
     * Available options
     *
     * =====> (string) cache_db_complete_path :
     * - the complete path (filename included) of the SQLITE database
     *
     * ====> (int) automatic_vacuum_factor :
     * - Disable / Tune the automatic vacuum process
     * - The automatic vacuum process defragment the database file (and make it smaller)
     *   when a clean() or delete() is called
     *     0               => no automatic vacuum
     *     1               => systematic vacuum (when delete() or clean() methods are called)
     *     x (integer) > 1 => automatic vacuum randomly 1 times on x clean() or delete()
     *
     * @var array Available options
     */
    protected $_options = array(
        'cache_db_complete_path' => null,
        'automatic_vacuum_factor' => 10
    );

    /**
     * DB ressource
     *
     * @var mixed $_db
     */
    private $_db = null;

    /**
     * Boolean to store if the structure has benn checked or not
     *
     * @var boolean $_structureChecked
     */
    private $_structureChecked = false;

    /**
     * Constructor
     *
     * @param  array $options Associative array of options
     * @throws Zend_cache_Exception
     * @return void
     */
    public function __construct(array $options = array())
    {
        parent::__construct($options);
        if ($this->_options['cache_db_complete_path'] === null) {
            Zend_Cache::throwException('cache_db_complete_path option has to set');
        }
        if (!extension_loaded('sqlite')) {
            Zend_Cache::throwException("Cannot use SQLite storage because the 'sqlite' extension is not loaded in the current PHP environment");
        }
        $this->_getConnection();
    }

    /**
     * Destructor
     *
     * @return void
     */
    public function __destruct()
    {
        @sqlite_close($this->_getConnection());
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false Cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $this->_checkAndBuildStructure();
        $sql = "SELECT content FROM cache WHERE id='$id'";
        if (!$doNotTestCacheValidity) {
            $sql = $sql . " AND (expire=0 OR expire>" . time() . ')';
        }
        $result = $this->_query($sql);
        $row = @sqlite_fetch_array($result);
        if ($row) {
            return $row['content'];
        }
        return false;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param string $id Cache id
     * @return mixed|false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        $this->_checkAndBuildStructure();
        $sql = "SELECT lastModified FROM cache WHERE id='$id' AND (expire=0 OR expire>" . time() . ')';
        $result = $this->_query($sql);
        $row = @sqlite_fetch_array($result);
        if ($row) {
            return ((int) $row['lastModified']);
        }
        return false;
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
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $this->_checkAndBuildStructure();
        $lifetime = $this->getLifetime($specificLifetime);
        $data = @sqlite_escape_string($data);
        $mktime = time();
        if ($lifetime === null) {
            $expire = 0;
        } else {
            $expire = $mktime + $lifetime;
        }
        $this->_query("DELETE FROM cache WHERE id='$id'");
        $sql = "INSERT INTO cache (id, content, lastModified, expire) VALUES ('$id', '$data', $mktime, $expire)";
        $res = $this->_query($sql);
        if (!$res) {
            $this->_log("Zend_Cache_Backend_Sqlite::save() : impossible to store the cache id=$id");
            return false;
        }
        $res = true;
        foreach ($tags as $tag) {
            $res = $this->_registerTag($id, $tag) && $res;
        }
        return $res;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        $this->_checkAndBuildStructure();
        $res = $this->_query("SELECT COUNT(*) AS nbr FROM cache WHERE id='$id'");
        $result1 = @sqlite_fetch_single($res);
        $result2 = $this->_query("DELETE FROM cache WHERE id='$id'");
        $result3 = $this->_query("DELETE FROM tag WHERE id='$id'");
        $this->_automaticVacuum();
        return ($result1 && $result2 && $result3);
    }

    /**
     * Clean some cache records
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
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        $this->_checkAndBuildStructure();
        $return = $this->_clean($mode, $tags);
        $this->_automaticVacuum();
        return $return;
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        $this->_checkAndBuildStructure();
        $res = $this->_query("SELECT id FROM cache WHERE (expire=0 OR expire>" . time() . ")");
        $result = array();
        while ($id = @sqlite_fetch_single($res)) {
            $result[] = $id;
        }
        return $result;
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        $this->_checkAndBuildStructure();
        $res = $this->_query("SELECT DISTINCT(name) AS name FROM tag");
        $result = array();
        while ($id = @sqlite_fetch_single($res)) {
            $result[] = $id;
        }
        return $result;
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
        $first = true;
        $ids = array();
        foreach ($tags as $tag) {
            $res = $this->_query("SELECT DISTINCT(id) AS id FROM tag WHERE name='$tag'");
            if (!$res) {
                return array();
            }
            $rows = @sqlite_fetch_all($res, SQLITE_ASSOC);
            $ids2 = array();
            foreach ($rows as $row) {
                $ids2[] = $row['id'];
            }
            if ($first) {
                $ids = $ids2;
                $first = false;
            } else {
                $ids = array_intersect($ids, $ids2);
            }
        }
        $result = array();
        foreach ($ids as $id) {
            $result[] = $id;
        }
        return $result;
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
        $res = $this->_query("SELECT id FROM cache");
        $rows = @sqlite_fetch_all($res, SQLITE_ASSOC);
        $result = array();
        foreach ($rows as $row) {
            $id = $row['id'];
            $matching = false;
            foreach ($tags as $tag) {
                $res = $this->_query("SELECT COUNT(*) AS nbr FROM tag WHERE name='$tag' AND id='$id'");
                if (!$res) {
                    return array();
                }
                $nbr = (int) @sqlite_fetch_single($res);
                if ($nbr > 0) {
                    $matching = true;
                }
            }
            if (!$matching) {
                $result[] = $id;
            }
        }
        return $result;
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
        $first = true;
        $ids = array();
        foreach ($tags as $tag) {
            $res = $this->_query("SELECT DISTINCT(id) AS id FROM tag WHERE name='$tag'");
            if (!$res) {
                return array();
            }
            $rows = @sqlite_fetch_all($res, SQLITE_ASSOC);
            $ids2 = array();
            foreach ($rows as $row) {
                $ids2[] = $row['id'];
            }
            if ($first) {
                $ids = $ids2;
                $first = false;
            } else {
                $ids = array_merge($ids, $ids2);
            }
        }
        $result = array();
        foreach ($ids as $id) {
            $result[] = $id;
        }
        return $result;
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        $dir = dirname($this->_options['cache_db_complete_path']);
        $free = disk_free_space($dir);
        $total = disk_total_space($dir);
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
        $tags = array();
        $res = $this->_query("SELECT name FROM tag WHERE id='$id'");
        if ($res) {
            $rows = @sqlite_fetch_all($res, SQLITE_ASSOC);
            foreach ($rows as $row) {
                $tags[] = $row['name'];
            }
        }
        $this->_query('CREATE TABLE cache (id TEXT PRIMARY KEY, content BLOB, lastModified INTEGER, expire INTEGER)');
        $res = $this->_query("SELECT lastModified,expire FROM cache WHERE id='$id'");
        if (!$res) {
            return false;
        }
        $row = @sqlite_fetch_array($res, SQLITE_ASSOC);
        return array(
            'tags' => $tags,
            'mtime' => $row['lastModified'],
            'expire' => $row['expire']
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
        $sql = "SELECT expire FROM cache WHERE id='$id' AND (expire=0 OR expire>" . time() . ')';
        $res = $this->_query($sql);
        if (!$res) {
            return false;
        }
        $expire = @sqlite_fetch_single($res);
        $newExpire = $expire + $extraLifetime;
        $res = $this->_query("UPDATE cache SET lastModified=" . time() . ", expire=$newExpire WHERE id='$id'");
        if ($res) {
            return true;
        } else {
            return false;
        }
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
     * @param string $id Cache id
     */
    public function ___expire($id)
    {
        $time = time() - 1;
        $this->_query("UPDATE cache SET lastModified=$time, expire=$time WHERE id='$id'");
    }

    /**
     * Return the connection resource
     *
     * If we are not connected, the connection is made
     *
     * @throws Zend_Cache_Exception
     * @return resource Connection resource
     */
    private function _getConnection()
    {
        if (is_resource($this->_db)) {
            return $this->_db;
        } else {
            $this->_db = @sqlite_open($this->_options['cache_db_complete_path']);
            if (!(is_resource($this->_db))) {
                Zend_Cache::throwException("Impossible to open " . $this->_options['cache_db_complete_path'] . " cache DB file");
            }
            return $this->_db;
        }
    }

    /**
     * Execute an SQL query silently
     *
     * @param string $query SQL query
     * @return mixed|false query results
     */
    private function _query($query)
    {
        $db = $this->_getConnection();
        if (is_resource($db)) {
            $res = @sqlite_query($db, $query);
            if ($res === false) {
                return false;
            } else {
                return $res;
            }
        }
        return false;
    }

    /**
     * Deal with the automatic vacuum process
     *
     * @return void
     */
    private function _automaticVacuum()
    {
        if ($this->_options['automatic_vacuum_factor'] > 0) {
            $rand = rand(1, $this->_options['automatic_vacuum_factor']);
            if ($rand == 1) {
                $this->_query('VACUUM');
                @sqlite_close($this->_getConnection());
            }
        }
    }

    /**
     * Register a cache id with the given tag
     *
     * @param  string $id  Cache id
     * @param  string $tag Tag
     * @return boolean True if no problem
     */
    private function _registerTag($id, $tag) {
        $res = $this->_query("DELETE FROM TAG WHERE name='$tag' AND id='$id'");
        $res = $this->_query("INSERT INTO tag (name, id) VALUES ('$tag', '$id')");
        if (!$res) {
            $this->_log("Zend_Cache_Backend_Sqlite::_registerTag() : impossible to register tag=$tag on id=$id");
            return false;
        }
        return true;
    }

    /**
     * Build the database structure
     *
     * @return false
     */
    private function _buildStructure()
    {
        $this->_query('DROP INDEX tag_id_index');
        $this->_query('DROP INDEX tag_name_index');
        $this->_query('DROP INDEX cache_id_expire_index');
        $this->_query('DROP TABLE version');
        $this->_query('DROP TABLE cache');
        $this->_query('DROP TABLE tag');
        $this->_query('CREATE TABLE version (num INTEGER PRIMARY KEY)');
        $this->_query('CREATE TABLE cache (id TEXT PRIMARY KEY, content BLOB, lastModified INTEGER, expire INTEGER)');
        $this->_query('CREATE TABLE tag (name TEXT, id TEXT)');
        $this->_query('CREATE INDEX tag_id_index ON tag(id)');
        $this->_query('CREATE INDEX tag_name_index ON tag(name)');
        $this->_query('CREATE INDEX cache_id_expire_index ON cache(id, expire)');
        $this->_query('INSERT INTO version (num) VALUES (1)');
    }

    /**
     * Check if the database structure is ok (with the good version)
     *
     * @return boolean True if ok
     */
    private function _checkStructureVersion()
    {
        $result = $this->_query("SELECT num FROM version");
        if (!$result) return false;
        $row = @sqlite_fetch_array($result);
        if (!$row) {
            return false;
        }
        if (((int) $row['num']) != 1) {
            // old cache structure
            $this->_log('Zend_Cache_Backend_Sqlite::_checkStructureVersion() : old cache structure version detected => the cache is going to be dropped');
            return false;
        }
        return true;
    }

    /**
     * Clean some cache records
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
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @return boolean True if no problem
     */
    private function _clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                $res1 = $this->_query('DELETE FROM cache');
                $res2 = $this->_query('DELETE FROM tag');
                return $res1 && $res2;
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                $mktime = time();
                $res1 = $this->_query("DELETE FROM tag WHERE id IN (SELECT id FROM cache WHERE expire>0 AND expire<=$mktime)");
                $res2 = $this->_query("DELETE FROM cache WHERE expire>0 AND expire<=$mktime");
                return $res1 && $res2;
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                $ids = $this->getIdsMatchingTags($tags);
                $result = true;
                foreach ($ids as $id) {
                    $result = $this->remove($id) && $result;
                }
                return $result;
                break;
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                $ids = $this->getIdsNotMatchingTags($tags);
                $result = true;
                foreach ($ids as $id) {
                    $result = $this->remove($id) && $result;
                }
                return $result;
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                $ids = $this->getIdsMatchingAnyTags($tags);
                $result = true;
                foreach ($ids as $id) {
                    $result = $this->remove($id) && $result;
                }
                return $result;
                break;
            default:
                break;
        }
        return false;
    }

    /**
     * Check if the database structure is ok (with the good version), if no : build it
     *
     * @throws Zend_Cache_Exception
     * @return boolean True if ok
     */
    private function _checkAndBuildStructure()
    {
        if (!($this->_structureChecked)) {
            if (!$this->_checkStructureVersion()) {
                $this->_buildStructure();
                if (!$this->_checkStructureVersion()) {
                    Zend_Cache::throwException("Impossible to build cache structure in " . $this->_options['cache_db_complete_path']);
                }
            }
            $this->_structureChecked = true;
        }
        return true;
    }

}
