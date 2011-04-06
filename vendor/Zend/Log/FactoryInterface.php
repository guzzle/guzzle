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
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: FactoryInterface.php 23484 2010-12-10 03:57:59Z mjh_ca $
 */

/**
 * @category   Zend
 * @package    Zend_Log
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: FactoryInterface.php 23484 2010-12-10 03:57:59Z mjh_ca $
 */
interface Zend_Log_FactoryInterface
{
    /**
     * Construct a Zend_Log driver
     *
     * @param  array|Zen_Config $config
     * @return Zend_Log_FactoryInterface
     */
    static public function factory($config);
}
