<?php

namespace Guzzle\Tests\Common\Log;

use Guzzle\Common\Log\MonologLogAdapter;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

/**
 * @covers Guzzle\Common\Log\MonologLogAdapter
 */
class MonologLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\Log\MonologLogAdapter::log
     */
    public function testLogsMessagesToAdaptedObject()
    {
        $log = new Logger('test');
        $handler = new TestHandler();
        $log->pushHandler($handler);
        $adapter = new MonologLogAdapter($log);

        $adapter->log('test!', LOG_INFO);

        $this->assertTrue($handler->hasInfoRecords());
    }
}
