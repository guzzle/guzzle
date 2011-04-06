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
 * @package    Zend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Exception.php 23484 2010-12-10 03:57:59Z mjh_ca $
 */

/**
* @category   Zend
* @package    Zend
* @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
* @license    http://framework.zend.com/license/new-bsd     New BSD License
*/
class Zend_Exception extends Exception
{
    /**
     * @var null|Exception
     */
    private $_previous = null;

    /**
     * Construct the exception
     *
     * @param  string $msg
     * @param  int $code
     * @param  Exception $previous
     * @return void
     */
    public function __construct($msg = '', $code = 0, Exception $previous = null)
    {
        if (version_compare(PHP_VERSION, '5.3.0', '<')) {
            parent::__construct($msg, (int) $code);
            $this->_previous = $previous;
        } else {
            parent::__construct($msg, (int) $code, $previous);
        }
    }

    /**
     * Overloading
     *
     * For PHP < 5.3.0, provides access to the getPrevious() method.
     *
     * @param  string $method
     * @param  array $args
     * @return mixed
     */
    public function __call($method, array $args)
    {
        if ('getprevious' == strtolower($method)) {
            return $this->_getPrevious();
        }
        return null;
    }

    /**
     * String representation of the exception
     *
     * @return string
     */
    public function __toString()
    {
        if (version_compare(PHP_VERSION, '5.3.0', '<')) {
            if (null !== ($e = $this->getPrevious())) {
                return $e->__toString()
                       . "\n\nNext "
                       . parent::__toString();
            }
        }
        return parent::__toString();
    }

    /**
     * Returns previous Exception
     *
     * @return Exception|null
     */
    protected function _getPrevious()
    {
        return $this->_previous;
    }
}
