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
 * @package    Zend_Log
 * @subpackage Writer
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: ZendMonitor.php 23576 2010-12-23 23:25:44Z ramon $
 */

/** Zend_Log_Writer_Abstract */
// require_once 'Zend/Log/Writer/Abstract.php';

/**
 * @category   Zend
 * @package    Zend_Log
 * @subpackage Writer
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: ZendMonitor.php 23576 2010-12-23 23:25:44Z ramon $
 */
class Zend_Log_Writer_ZendMonitor extends Zend_Log_Writer_Abstract
{
    /**
     * Is Zend Monitor enabled?
     *
     * @var boolean
     */
    protected $_isEnabled = true;

    /**
     * Is this for a Zend Server intance?
     *
     * @var boolean
     */
    protected $_isZendServer = false;

    /**
     * @return void
     */
    public function __construct()
    {
        if (!function_exists('monitor_custom_event')) {
            $this->_isEnabled = false;
        }
        if (function_exists('zend_monitor_custom_event')) {
            $this->_isZendServer = true;
        }
    }

    /**
     * Create a new instance of Zend_Log_Writer_ZendMonitor
     *
     * @param  array|Zend_Config $config
     * @return Zend_Log_Writer_ZendMonitor
     */
    static public function factory($config)
    {
        return new self();
    }

    /**
     * Is logging to this writer enabled?
     *
     * If the Zend Monitor extension is not enabled, this log writer will
     * fail silently. You can query this method to determine if the log
     * writer is enabled.
     *
     * @return boolean
     */
    public function isEnabled()
    {
        return $this->_isEnabled;
    }

    /**
     * Log a message to this writer.
     *
     * @param  array $event log data event
     * @return void
     */
    public function write($event)
    {
        if (!$this->isEnabled()) {
            return;
        }

        parent::write($event);
    }

    /**
     * Write a message to the log.
     *
     * @param  array  $event log data event
     * @return void
     */
    protected function _write($event)
    {
        $priority = $event['priority'];
        $message  = $event['message'];
        unset($event['priority'], $event['message']);

        if (!empty($event)) {
            if ($this->_isZendServer) {
                // On Zend Server; third argument should be the event
                zend_monitor_custom_event($priority, $message, $event);
            } else {
                // On Zend Platform; third argument is severity -- either
                // 0 or 1 -- and fourth is optional (event)
                // Severity is either 0 (normal) or 1 (severe); classifying
                // notice, info, and debug as "normal", and all others as
                // "severe"
                monitor_custom_event($priority, $message, ($priority > 4) ? 0 : 1, $event);
            }
        } else {
            monitor_custom_event($priority, $message);
        }
    }
}
