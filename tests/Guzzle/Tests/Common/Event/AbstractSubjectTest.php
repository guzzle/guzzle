<?php

namespace Guzzle\Tests\Common\Event;

use Guzzle\Tests\Common\Mock\MockSubject;
use Guzzle\Common\Event\EventManager;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AbstractSubjectTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers \Guzzle\Common\Event\AbstractSubject::getEventManager
     */
    public function testGetEventManager()
    {
        $subject = new MockSubject();
        $mediator = $subject->getEventManager();
        $this->assertInstanceOf('Guzzle\Common\Event\EventManager', $mediator);
        $this->assertEquals($mediator, $subject->getEventManager());
    }
}