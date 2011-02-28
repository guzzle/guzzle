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
 * @version    $Id: Db.php 23576 2010-12-23 23:25:44Z ramon $
 */

/** Zend_Log_Writer_Abstract */
// require_once 'Zend/Log/Writer/Abstract.php';

/**
 * @category   Zend
 * @package    Zend_Log
 * @subpackage Writer
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Db.php 23576 2010-12-23 23:25:44Z ramon $
 */
class Zend_Log_Writer_Db extends Zend_Log_Writer_Abstract
{
    /**
     * Database adapter instance
     *
     * @var Zend_Db_Adapter
     */
    private $_db;

    /**
     * Name of the log table in the database
     *
     * @var string
     */
    private $_table;

    /**
     * Relates database columns names to log data field keys.
     *
     * @var null|array
     */
    private $_columnMap;

    /**
     * Class constructor
     *
     * @param Zend_Db_Adapter $db   Database adapter instance
     * @param string $table         Log table in database
     * @param array $columnMap
     * @return void
     */
    public function __construct($db, $table, $columnMap = null)
    {
        $this->_db    = $db;
        $this->_table = $table;
        $this->_columnMap = $columnMap;
    }

    /**
     * Create a new instance of Zend_Log_Writer_Db
     *
     * @param  array|Zend_Config $config
     * @return Zend_Log_Writer_Db
     * @throws Zend_Log_Exception
     */
    static public function factory($config)
    {
        $config = self::_parseConfig($config);
        $config = array_merge(array(
            'db'        => null,
            'table'     => null,
            'columnMap' => null,
        ), $config);

        if (isset($config['columnmap'])) {
            $config['columnMap'] = $config['columnmap'];
        }

        return new self(
            $config['db'],
            $config['table'],
            $config['columnMap']
        );
    }

    /**
     * Formatting is not possible on this writer
     *
     * @return void
     * @throws Zend_Log_Exception
     */
    public function setFormatter(Zend_Log_Formatter_Interface $formatter)
    {
        // require_once 'Zend/Log/Exception.php';
        throw new Zend_Log_Exception(get_class($this) . ' does not support formatting');
    }

    /**
     * Remove reference to database adapter
     *
     * @return void
     */
    public function shutdown()
    {
        $this->_db = null;
    }

    /**
     * Write a message to the log.
     *
     * @param  array  $event  event data
     * @return void
     */
    protected function _write($event)
    {
        if ($this->_db === null) {
            // require_once 'Zend/Log/Exception.php';
            throw new Zend_Log_Exception('Database adapter is null');
        }

        if ($this->_columnMap === null) {
            $dataToInsert = $event;
        } else {
            $dataToInsert = array();
            foreach ($this->_columnMap as $columnName => $fieldKey) {
                $dataToInsert[$columnName] = $event[$fieldKey];
            }
        }

        $this->_db->insert($this->_table, $dataToInsert);
    }
}
