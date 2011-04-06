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
 * @version    $Id: Abstract.php 23576 2010-12-23 23:25:44Z ramon $
 */

/** Zend_Log_Filter_Priority */
// require_once 'Zend/Log/Filter/Priority.php';

/**
 * @category   Zend
 * @package    Zend_Log
 * @subpackage Writer
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Abstract.php 23576 2010-12-23 23:25:44Z ramon $
 */
abstract class Zend_Log_Writer_Abstract implements Zend_Log_FactoryInterface
{
    /**
     * @var array of Zend_Log_Filter_Interface
     */
    protected $_filters = array();

    /**
     * Formats the log message before writing.
     *
     * @var Zend_Log_Formatter_Interface
     */
    protected $_formatter;

    /**
     * Add a filter specific to this writer.
     *
     * @param  Zend_Log_Filter_Interface  $filter
     * @return Zend_Log_Writer_Abstract
     */
    public function addFilter($filter)
    {
        if (is_int($filter)) {
            $filter = new Zend_Log_Filter_Priority($filter);
        }

        if (!$filter instanceof Zend_Log_Filter_Interface) {
            /** @see Zend_Log_Exception */
            // require_once 'Zend/Log/Exception.php';
            throw new Zend_Log_Exception('Invalid filter provided');
        }

        $this->_filters[] = $filter;
        return $this;
    }

    /**
     * Log a message to this writer.
     *
     * @param  array $event log data event
     * @return void
     */
    public function write($event)
    {
        foreach ($this->_filters as $filter) {
            if (! $filter->accept($event)) {
                return;
            }
        }

        // exception occurs on error
        $this->_write($event);
    }

    /**
     * Set a new formatter for this writer
     *
     * @param  Zend_Log_Formatter_Interface $formatter
     * @return Zend_Log_Writer_Abstract
     */
    public function setFormatter(Zend_Log_Formatter_Interface $formatter)
    {
        $this->_formatter = $formatter;
        return $this;
    }

    /**
     * Perform shutdown activites such as closing open resources
     *
     * @return void
     */
    public function shutdown()
    {}

    /**
     * Write a message to the log.
     *
     * @param  array  $event  log data event
     * @return void
     */
    abstract protected function _write($event);

    /**
     * Validate and optionally convert the config to array
     *
     * @param  array|Zend_Config $config Zend_Config or Array
     * @return array
     * @throws Zend_Log_Exception
     */
    static protected function _parseConfig($config)
    {
        if ($config instanceof Zend_Config) {
            $config = $config->toArray();
        }

        if (!is_array($config)) {
            // require_once 'Zend/Log/Exception.php';
            throw new Zend_Log_Exception(
                'Configuration must be an array or instance of Zend_Config'
            );
        }

        return $config;
    }
}
