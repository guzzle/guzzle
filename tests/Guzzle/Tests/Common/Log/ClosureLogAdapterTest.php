<?php

namespace Guzzle\Tests\Common\Log;

use Guzzle\Common\Log\LogAdapterInterface;
use Guzzle\Common\Log\ClosureLogAdapter;

class ClosureLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var string Variable that the closure will modifiy
     */
    public $modified;

    /**
     * @covers \Guzzle\Common\Log\ClosureLogAdapter
     */
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
     * @covers \Guzzle\Common\Log\ClosureLogAdapter
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExceptionWhenNotCallable()
    {
        $abc = 123;
        $this->adapter = new ClosureLogAdapter($abc);
    }
}
