<?php

namespace Guzzle\Tests\Common\Log;

use Guzzle\Common\Log\MonologLogAdapter;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MonologLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\Log\MonologLogAdapter::__construct
     * @expectedException InvalidArgumentException
     */
    public function testEnforcesType()
    {
        // A successful construction
        $log = new Logger('test');
        $log->pushHandler(new TestHandler());
        $adapter = new MonologLogAdapter($log);

        // Throws an exception
        $this->adapter = new MonologLogAdapter(new \stdClass());
    }

    /**
     * @covers Guzzle\Common\Log\MonologLogAdapter::log
     */
    public function testLogsMessagesToAdaptedObject()
    {
        $log = new Logger('test');
        $handler = new TestHandler();
        $log->pushHandler($handler);
        $adapter = new MonologLogAdapter($log);

        $adapter->log('test!', Logger::INFO);

        $this->assertTrue($handler->hasInfoRecords());
    }
}
