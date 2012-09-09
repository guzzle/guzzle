<?php

namespace Guzzle\Tests\Plugin\Backoff;

use Guzzle\Plugin\Backoff\CallbackBackoffStrategy;

/**
 * @covers Guzzle\Plugin\Backoff\CallbackBackoffStrategy
 */
class CallbackBackoffStrategyTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testEnsuresIsCallable()
    {
        $strategy = new CallbackBackoffStrategy(new \stdClass());
    }

    public function testRetriesWithCallable()
    {
        $strategy = new CallbackBackoffStrategy(function () {
            return 10;
        });
        $request = $this->getMock('Guzzle\Http\Message\Request', array(), array(), '', false);
        $this->assertEquals(10, $strategy->getBackoffPeriod(0, $request));
    }
}
