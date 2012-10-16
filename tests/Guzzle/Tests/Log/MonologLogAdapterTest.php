<?php

namespace Guzzle\Tests\Log;

use Guzzle\Log\MonologLogAdapter;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

/**
 * @covers Guzzle\Log\MonologLogAdapter
 */
class MonologLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
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
