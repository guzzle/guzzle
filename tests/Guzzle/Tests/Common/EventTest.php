<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Event;

class EventTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @return Event
     */
    private function getEvent()
    {
        return new Event(array(
            'test'  => '123',
            'other' => '456',
            'event' => 'test.notify'
        ));
    }

    /**
     * @covers Guzzle\Common\Event::__construct
     */
    public function testAllowsParameterInjection()
    {
        $event = new Event(array(
            'test' => '123'
        ));
        $this->assertEquals('123', $event['test']);
    }

    /**
     * @covers Guzzle\Common\Event::offsetGet
     * @covers Guzzle\Common\Event::offsetSet
     * @covers Guzzle\Common\Event::offsetUnset
     * @covers Guzzle\Common\Event::offsetExists
     */
    public function testImplementsArrayAccess()
    {
        $event = $this->getEvent();
        $this->assertEquals('123', $event['test']);
        $this->assertNull($event['foobar']);

        $this->assertTrue($event->offsetExists('test'));
        $this->assertFalse($event->offsetExists('foobar'));

        unset($event['test']);
        $this->assertFalse($event->offsetExists('test'));

        $event['test'] = 'new';
        $this->assertEquals('new', $event['test']);
    }

    /**
     * @covers Guzzle\Common\Event::getIterator
     */
    public function testImplementsIteratorAggregate()
    {
        $event = $this->getEvent();
        $this->assertInstanceOf('ArrayIterator', $event->getIterator());
    }
}
