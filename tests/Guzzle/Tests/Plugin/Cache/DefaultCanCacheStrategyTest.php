<?php

namespace Guzzle\Tests\Plugin\Cache;

use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Cache\DefaultCanCacheStrategy;

/**
 * @covers Guzzle\Plugin\Cache\DefaultCanCacheStrategy
 */
class DefaultCanCacheStrategyTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testReturnsRequestcanCacheRequest()
    {
        $strategy = new DefaultCanCacheStrategy();
        $response = $this->getMockBuilder('Guzzle\Http\Message\Request')
            ->disableOriginalConstructor()
            ->setMethods(array('canCache'))
            ->getMock();

        $response->expects($this->once())
            ->method('canCache')
            ->will($this->returnValue(true));

        $this->assertTrue($strategy->canCacheRequest($response));
    }
}
