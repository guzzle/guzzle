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
 * @subpackage Formatter
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Simple.php 23576 2010-12-23 23:25:44Z ramon $
 */

/** Zend_Log_Formatter_Interface */
// require_once 'Zend/Log/Formatter/Interface.php';

/**
 * @category   Zend
 * @package    Zend_Log
 * @subpackage Formatter
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Simple.php 23576 2010-12-23 23:25:44Z ramon $
 */
class Zend_Log_Formatter_Simple implements Zend_Log_Formatter_Interface
{
    /**
     * @var string
     */
    protected $_format;

    const DEFAULT_FORMAT = '%timestamp% %priorityName% (%priority%): %message%';

    /**
     * Class constructor
     *
     * @param  null|string  $format  Format specifier for log messages
     * @return void
     * @throws Zend_Log_Exception
     */
    public function __construct($format = null)
    {
        if ($format === null) {
            $format = self::DEFAULT_FORMAT . PHP_EOL;
        }

        if (! is_string($format)) {
            // require_once 'Zend/Log/Exception.php';
            throw new Zend_Log_Exception('Format must be a string');
        }

        $this->_format = $format;
    }

    /**
     * Formats data into a single line to be written by the writer.
     *
     * @param  array    $event    event data
     * @return string             formatted line to write to the log
     */
    public function format($event)
    {
        $output = $this->_format;
        foreach ($event as $name => $value) {

            if ((is_object($value) && !method_exists($value,'__toString'))
                || is_array($value)) {

                $value = gettype($value);
            }

            $output = str_replace("%$name%", $value, $output);
        }
        return $output;
    }

}
