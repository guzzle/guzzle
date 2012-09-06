<?php

namespace Guzzle\Tests\Log;

use Guzzle\Log\ClosureLogAdapter;

/**
 * @covers Guzzle\Log\ClosureLogAdapter
 */
class ClosureLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var string Variable that the closure will modify
     */
    public $modified;

    public function testClosure()
    {
        $that = $this;

        $this->adapter = new ClosureLogAdapter(function($message, $priority, $extras = null) use ($that) {
            $that->modified = array($message, $priority, $extras);
        });

        $this->adapter->log('test', \LOG_NOTICE, 'localhost');
        $this->assertEquals(array('test', \LOG_NOTICE, 'localhost'), $this->modified);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExceptionWhenNotCallable()
    {
        $abc = 123;
        $this->adapter = new ClosureLogAdapter($abc);
    }
}
