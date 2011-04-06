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
 * @version    $Id: File.php 23330 2010-11-14 20:08:09Z mabe $
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
class Zend_Cache_Frontend_File extends Zend_Cache_Core
{

    /**
     * Consts for master_files_mode
     */
    const MODE_AND = 'AND';
    const MODE_OR  = 'OR';

    /**
     * Available options
     *
     * ====> (string) master_file :
     * - a complete path of the master file
     * - deprecated (see master_files)
     *
     * ====> (array) master_files :
     * - an array of complete path of master files
     * - this option has to be set !
     *
     * ====> (string) master_files_mode :
     * - Zend_Cache_Frontend_File::MODE_AND or Zend_Cache_Frontend_File::MODE_OR
     * - if MODE_AND, then all master files have to be touched to get a cache invalidation
     * - if MODE_OR (default), then a single touched master file is enough to get a cache invalidation
     *
     * ====> (boolean) ignore_missing_master_files
     * - if set to true, missing master files are ignored silently
     * - if set to false (default), an exception is thrown if there is a missing master file
     * @var array available options
     */
    protected $_specificOptions = array(
        'master_file' => null,
        'master_files' => null,
        'master_files_mode' => 'OR',
        'ignore_missing_master_files' => false
    );

    /**
     * Master file mtimes
     *
     * Array of int
     *
     * @var array
     */
    private $_masterFile_mtimes = null;

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
        if (!isset($this->_specificOptions['master_files'])) {
            Zend_Cache::throwException('master_files option must be set');
        }
    }

    /**
     * Change the master_files option
     *
     * @param array $masterFiles the complete paths and name of the master files
     */
    public function setMasterFiles(array $masterFiles)
    {
        $this->_specificOptions['master_file']  = null; // to keep a compatibility
        $this->_specificOptions['master_files'] = null;
        $this->_masterFile_mtimes = array();

        clearstatcache();
        $i = 0;
        foreach ($masterFiles as $masterFile) {
            $mtime = @filemtime($masterFile);

            if (!$this->_specificOptions['ignore_missing_master_files'] && !$mtime) {
                Zend_Cache::throwException('Unable to read master_file : ' . $masterFile);
            }

            $this->_masterFile_mtimes[$i] = $mtime;
            $this->_specificOptions['master_files'][$i] = $masterFile;
            if ($i === 0) { // to keep a compatibility
                $this->_specificOptions['master_files'] = $masterFile;
            }

            $i++;
        }
    }

    /**
     * Change the master_file option
     *
     * To keep the compatibility
     *
     * @deprecated
     * @param string $masterFile the complete path and name of the master file
     */
    public function setMasterFile($masterFile)
    {
          $this->setMasterFiles(array($masterFile));
    }

    /**
     * Public frontend to set an option
     *
     * Just a wrapper to get a specific behaviour for master_file
     *
     * @param  string $name  Name of the option
     * @param  mixed  $value Value of the option
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setOption($name, $value)
    {
        if ($name == 'master_file') {
            $this->setMasterFile($value);
        } else if ($name == 'master_files') {
            $this->setMasterFiles($value);
        } else {
            parent::setOption($name, $value);
        }
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @param  boolean $doNotUnserialize       Do not serialize (even if automatic_serialization is true) => for internal use
     * @return mixed|false Cached datas
     */
    public function load($id, $doNotTestCacheValidity = false, $doNotUnserialize = false)
    {
        if (!$doNotTestCacheValidity) {
            if ($this->test($id)) {
                return parent::load($id, true, $doNotUnserialize);
            }
            return false;
        }
        return parent::load($id, true, $doNotUnserialize);
    }

    /**
     * Test if a cache is available for the given id
     *
     * @param  string $id Cache id
     * @return int|false Last modified time of cache entry if it is available, false otherwise
     */
    public function test($id)
    {
        $lastModified = parent::test($id);
        if ($lastModified) {
            if ($this->_specificOptions['master_files_mode'] == self::MODE_AND) {
                // MODE_AND
                foreach($this->_masterFile_mtimes as $masterFileMTime) {
                    if ($masterFileMTime) {
                        if ($lastModified > $masterFileMTime) {
                            return $lastModified;
                        }
                    }
                }
            } else {
                // MODE_OR
                $res = true;
                foreach($this->_masterFile_mtimes as $masterFileMTime) {
                    if ($masterFileMTime) {
                        if ($lastModified <= $masterFileMTime) {
                            return false;
                        }
                    }
                }
                return $lastModified;
            }
        }
        return false;
    }

}

