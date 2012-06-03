<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Event;
use Guzzle\Common\AbstractHasDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcher;

class AbstractHasAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\AbstractHasDispatcher::getAllEvents
     */
    public function testDoesNotRequireRegisteredEvents()
    {
        $this->assertEquals(array(), AbstractHasDispatcher::getAllEvents());
    }

    /**
     * @covers Guzzle\Common\AbstractHasDispatcher::setEventDispatcher
     * @covers Guzzle\Common\AbstractHasDispatcher::getEventDispatcher
     */
    public function testAllowsDispatcherToBeInjected()
    {
        $d = new EventDispatcher();
        $mock = $this->getMockForAbstractClass('Guzzle\Common\AbstractHasDispatcher');
        $this->assertSame($mock, $mock->setEventDispatcher($d));
        $this->assertSame($d, $mock->getEventDispatcher());
    }

    /**
     * @covers Guzzle\Common\AbstractHasDispatcher::getEventDispatcher
     */
    public function testCreatesDefaultEventDispatcherIfNeeded()
    {
        $mock = $this->getMockForAbstractClass('Guzzle\Common\AbstractHasDispatcher');
        $this->assertInstanceOf('Symfony\Component\EventDispatcher\EventDispatcher', $mock->getEventDispatcher());
    }

    /**
     * @covers Guzzle\Common\AbstractHasDispatcher::dispatch
     */
    public function testHelperDispatchesEvents()
    {
        $data = array();
        $mock = $this->getMockForAbstractClass('Guzzle\Common\AbstractHasDispatcher');
        $mock->getEventDispatcher()->addListener('test', function(Event $e) use (&$data) {
            $data = $e->getIterator()->getArrayCopy();
        });
        $mock->dispatch('test', array(
            'param' => 'abc'
        ));
        $this->assertEquals(array(
            'param' => 'abc',
        ), $data);
    }

    /**
     * @covers Guzzle\Common\AbstractHasDispatcher::addSubscriber
     */
    public function testHelperAttachesSubscribers()
    {
        $mock = $this->getMockForAbstractClass('Guzzle\Common\AbstractHasDispatcher');
        $subscriber = $this->getMockForAbstractClass('Symfony\Component\EventDispatcher\EventSubscriberInterface');

        $dispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcher')
            ->setMethods(array('addSubscriber'))
            ->getMock();

        $dispatcher->expects($this->once())
            ->method('addSubscriber');

        $mock->setEventDispatcher($dispatcher);
        $mock->addSubscriber($subscriber);
    }
}
