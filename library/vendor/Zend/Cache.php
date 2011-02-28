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
 * @version    $Id: Cache.php 23154 2010-10-18 17:41:06Z mabe $
 */


/**
 * @package    Zend_Cache
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
abstract class Zend_Cache
{

    /**
     * Standard frontends
     *
     * @var array
     */
    public static $standardFrontends = array('Core', 'Output', 'Class', 'File', 'Function', 'Page');

    /**
     * Standard backends
     *
     * @var array
     */
    public static $standardBackends = array('File', 'Sqlite', 'Memcached', 'Libmemcached', 'Apc', 'ZendPlatform',
                                            'Xcache', 'TwoLevels', 'ZendServer_Disk', 'ZendServer_ShMem');

    /**
     * Standard backends which implement the ExtendedInterface
     *
     * @var array
     */
    public static $standardExtendedBackends = array('File', 'Apc', 'TwoLevels', 'Memcached', 'Libmemcached', 'Sqlite');

    /**
     * Only for backward compatibility (may be removed in next major release)
     *
     * @var array
     * @deprecated
     */
    public static $availableFrontends = array('Core', 'Output', 'Class', 'File', 'Function', 'Page');

    /**
     * Only for backward compatibility (may be removed in next major release)
     *
     * @var array
     * @deprecated
     */
    public static $availableBackends = array('File', 'Sqlite', 'Memcached', 'Libmemcached', 'Apc', 'ZendPlatform', 'Xcache', 'TwoLevels');

    /**
     * Consts for clean() method
     */
    const CLEANING_MODE_ALL              = 'all';
    const CLEANING_MODE_OLD              = 'old';
    const CLEANING_MODE_MATCHING_TAG     = 'matchingTag';
    const CLEANING_MODE_NOT_MATCHING_TAG = 'notMatchingTag';
    const CLEANING_MODE_MATCHING_ANY_TAG = 'matchingAnyTag';

    /**
     * Factory
     *
     * @param mixed  $frontend        frontend name (string) or Zend_Cache_Frontend_ object
     * @param mixed  $backend         backend name (string) or Zend_Cache_Backend_ object
     * @param array  $frontendOptions associative array of options for the corresponding frontend constructor
     * @param array  $backendOptions  associative array of options for the corresponding backend constructor
     * @param boolean $customFrontendNaming if true, the frontend argument is used as a complete class name ; if false, the frontend argument is used as the end of "Zend_Cache_Frontend_[...]" class name
     * @param boolean $customBackendNaming if true, the backend argument is used as a complete class name ; if false, the backend argument is used as the end of "Zend_Cache_Backend_[...]" class name
     * @param boolean $autoload if true, there will no // require_once for backend and frontend (useful only for custom backends/frontends)
     * @throws Zend_Cache_Exception
     * @return Zend_Cache_Core|Zend_Cache_Frontend
     */
    public static function factory($frontend, $backend, $frontendOptions = array(), $backendOptions = array(), $customFrontendNaming = false, $customBackendNaming = false, $autoload = false)
    {
        if (is_string($backend)) {
            $backendObject = self::_makeBackend($backend, $backendOptions, $customBackendNaming, $autoload);
        } else {
            if ((is_object($backend)) && (in_array('Zend_Cache_Backend_Interface', class_implements($backend)))) {
                $backendObject = $backend;
            } else {
                self::throwException('backend must be a backend name (string) or an object which implements Zend_Cache_Backend_Interface');
            }
        }
        if (is_string($frontend)) {
            $frontendObject = self::_makeFrontend($frontend, $frontendOptions, $customFrontendNaming, $autoload);
        } else {
            if (is_object($frontend)) {
                $frontendObject = $frontend;
            } else {
                self::throwException('frontend must be a frontend name (string) or an object');
            }
        }
        $frontendObject->setBackend($backendObject);
        return $frontendObject;
    }

    /**
     * Backend Constructor
     *
     * @param string  $backend
     * @param array   $backendOptions
     * @param boolean $customBackendNaming
     * @param boolean $autoload
     * @return Zend_Cache_Backend
     */
    public static function _makeBackend($backend, $backendOptions, $customBackendNaming = false, $autoload = false)
    {
        if (!$customBackendNaming) {
            $backend  = self::_normalizeName($backend);
        }
        if (in_array($backend, Zend_Cache::$standardBackends)) {
            // we use a standard backend
            $backendClass = 'Zend_Cache_Backend_' . $backend;
            // security controls are explicit
            // require_once str_replace('_', DIRECTORY_SEPARATOR, $backendClass) . '.php';
        } else {
            // we use a custom backend
            if (!preg_match('~^[\w]+$~D', $backend)) {
                Zend_Cache::throwException("Invalid backend name [$backend]");
            }
            if (!$customBackendNaming) {
                // we use this boolean to avoid an API break
                $backendClass = 'Zend_Cache_Backend_' . $backend;
            } else {
                $backendClass = $backend;
            }
            if (!$autoload) {
                $file = str_replace('_', DIRECTORY_SEPARATOR, $backendClass) . '.php';
                if (!(self::_isReadable($file))) {
                    self::throwException("file $file not found in include_path");
                }
                // require_once $file;
            }
        }
        return new $backendClass($backendOptions);
    }

    /**
     * Frontend Constructor
     *
     * @param string  $frontend
     * @param array   $frontendOptions
     * @param boolean $customFrontendNaming
     * @param boolean $autoload
     * @return Zend_Cache_Core|Zend_Cache_Frontend
     */
    public static function _makeFrontend($frontend, $frontendOptions = array(), $customFrontendNaming = false, $autoload = false)
    {
        if (!$customFrontendNaming) {
            $frontend = self::_normalizeName($frontend);
        }
        if (in_array($frontend, self::$standardFrontends)) {
            // we use a standard frontend
            // For perfs reasons, with frontend == 'Core', we can interact with the Core itself
            $frontendClass = 'Zend_Cache_' . ($frontend != 'Core' ? 'Frontend_' : '') . $frontend;
            // security controls are explicit
            // require_once str_replace('_', DIRECTORY_SEPARATOR, $frontendClass) . '.php';
        } else {
            // we use a custom frontend
            if (!preg_match('~^[\w]+$~D', $frontend)) {
                Zend_Cache::throwException("Invalid frontend name [$frontend]");
            }
            if (!$customFrontendNaming) {
                // we use this boolean to avoid an API break
                $frontendClass = 'Zend_Cache_Frontend_' . $frontend;
            } else {
                $frontendClass = $frontend;
            }
            if (!$autoload) {
                $file = str_replace('_', DIRECTORY_SEPARATOR, $frontendClass) . '.php';
                if (!(self::_isReadable($file))) {
                    self::throwException("file $file not found in include_path");
                }
                // require_once $file;
            }
        }
        return new $frontendClass($frontendOptions);
    }

    /**
     * Throw an exception
     *
     * Note : for perf reasons, the "load" of Zend/Cache/Exception is dynamic
     * @param  string $msg  Message for the exception
     * @throws Zend_Cache_Exception
     */
    public static function throwException($msg, Exception $e = null)
    {
        // For perfs reasons, we use this dynamic inclusion
        // require_once 'Zend/Cache/Exception.php';
        throw new Zend_Cache_Exception($msg, 0, $e);
    }

    /**
     * Normalize frontend and backend names to allow multiple words TitleCased
     *
     * @param  string $name  Name to normalize
     * @return string
     */
    protected static function _normalizeName($name)
    {
        $name = ucfirst(strtolower($name));
        $name = str_replace(array('-', '_', '.'), ' ', $name);
        $name = ucwords($name);
        $name = str_replace(' ', '', $name);
        if (stripos($name, 'ZendServer') === 0) {
            $name = 'ZendServer_' . substr($name, strlen('ZendServer'));
        }

        return $name;
    }

    /**
     * Returns TRUE if the $filename is readable, or FALSE otherwise.
     * This function uses the PHP include_path, where PHP's is_readable()
     * does not.
     *
     * Note : this method comes from Zend_Loader (see #ZF-2891 for details)
     *
     * @param string   $filename
     * @return boolean
     */
    private static function _isReadable($filename)
    {
        if (!$fh = @fopen($filename, 'r', true)) {
            return false;
        }
        @fclose($fh);
        return true;
    }

}
