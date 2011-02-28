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
 * @version    $Id: Output.php 20096 2010-01-06 02:05:09Z bkarwin $
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
class Zend_Cache_Frontend_Output extends Zend_Cache_Core
{

    private $_idStack = array();

    /**
     * Constructor
     *
     * @param  array $options Associative array of options
     * @return void
     */
    public function __construct(array $options = array())
    {
        parent::__construct($options);
        $this->_idStack = array();
    }

    /**
     * Start the cache
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @param  boolean $echoData               If set to true, datas are sent to the browser if the cache is hit (simpy returned else)
     * @return mixed True if the cache is hit (false else) with $echoData=true (default) ; string else (datas)
     */
    public function start($id, $doNotTestCacheValidity = false, $echoData = true)
    {
        $data = $this->load($id, $doNotTestCacheValidity);
        if ($data !== false) {
            if ( $echoData ) {
                echo($data);
                return true;
            } else {
                return $data;
            }
        }
        ob_start();
        ob_implicit_flush(false);
        $this->_idStack[] = $id;
        return false;
    }

    /**
     * Stop the cache
     *
     * @param  array   $tags             Tags array
     * @param  int     $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @param  string  $forcedDatas      If not null, force written datas with this
     * @param  boolean $echoData         If set to true, datas are sent to the browser
     * @param  int     $priority         integer between 0 (very low priority) and 10 (maximum priority) used by some particular backends
     * @return void
     */
    public function end($tags = array(), $specificLifetime = false, $forcedDatas = null, $echoData = true, $priority = 8)
    {
        if ($forcedDatas === null) {
            $data = ob_get_contents();
            ob_end_clean();
        } else {
            $data =& $forcedDatas;
        }
        $id = array_pop($this->_idStack);
        if ($id === null) {
            Zend_Cache::throwException('use of end() without a start()');
        }
        $this->save($data, $id, $tags, $specificLifetime, $priority);
        if ($echoData) {
            echo($data);
        }
    }

}
