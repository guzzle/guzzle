<?php

namespace Guzzle\Tests\Common\Log;

use Guzzle\Common\Log\LogAdapterInterface;
use Guzzle\Common\Log\ZendLogAdapter;
use Guzzle\Common\Collection;

/**
 * Test class for ZendLogAdapter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 * @covers Guzzle\Common\Log\AbstractLogAdapter
 * @covers Guzzle\Common\Log\ZendLogAdapter
 */
class ZendLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ZendLogAdapter
     */
    protected $adapter;

    /**
     * @var Zend_Log
     */
    protected $log;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {        
        $this->log = new \Zend_Log(new \Zend_Log_Writer_Stream('php://output'));
        $this->adapter = new ZendLogAdapter($this->log);
    }

    /**
     * @covers Guzzle\Common\Log\AbstractLogAdapter::__construct
     * @expectedException InvalidArgumentException
     */
    public function testEnforcesType()
    {
        // A successful construction
        $this->adapter = new ZendLogAdapter($this->log, new Collection());
        
        // Throws an exception
        $this->adapter = new ZendLogAdapter(new \stdClass());
    }

    /**
     * @covers Guzzle\Common\Log\ZendLogAdapter::log
     * @outputBuffering enabled
     */
    public function testLogsMessagesToAdaptedObject()
    {
        // Test without a priority
        $this->adapter->log('test', \LOG_NOTICE, 'guzzle.common.log.adapter.zend_log_adapter', 'localhost');
        $this->assertEquals(1, substr_count(ob_get_contents(), 'test'));

        // Test with a priority
        $this->adapter->log('test', \LOG_ALERT);
        $this->assertEquals(2, substr_count(ob_get_contents(), 'test'));
    }

    /**
     * @covers Guzzle\Common\Log\AbstractLogAdapter::getLogObject
     */
    public function testExposesAdaptedLogObject()
    {
        $this->assertEquals($this->log, $this->adapter->getLogObject());
    }
}