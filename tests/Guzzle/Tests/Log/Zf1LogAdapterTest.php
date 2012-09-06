<?php

namespace Guzzle\Tests\Log;

use Guzzle\Log\Zf1LogAdapter;

// Until I can figure out how to get this to work with composer, this is the
// best I could come up with...
require 'Zend_Log.php';

/**
 * @covers Guzzle\Log\AbstractLogAdapter
 * @covers Guzzle\Log\Zf1LogAdapter
 */
class Zf1LogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var Zf1LogAdapter
     */
    protected $adapter;

    /**
     * @var \Zend_Log
     */
    protected $log;

    /**
     * @var resource Stream containing log data
     */
    protected $stream;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        $this->stream = fopen('php://temp', 'r+');
        $this->log = new \Zend_Log(new \Zend_Log_Writer_Stream($this->stream));
        $this->adapter = new Zf1LogAdapter($this->log);
    }

    public function testLogsMessagesToAdaptedObject()
    {
        // Test without a priority
        $this->adapter->log('test', \LOG_NOTICE, 'guzzle.common.log.adapter.zend_log_adapter', 'localhost');
        rewind($this->stream);
        $this->assertEquals(1, substr_count(stream_get_contents($this->stream), 'test'));

        // Test with a priority
        $this->adapter->log('test', \LOG_ALERT);
        rewind($this->stream);
        $this->assertEquals(2, substr_count(stream_get_contents($this->stream), 'test'));
    }

    public function testExposesAdaptedLogObject()
    {
        $this->assertEquals($this->log, $this->adapter->getLogObject());
    }
}
