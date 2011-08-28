<?php

namespace Guzzle\Tests\Common\Event;

use Guzzle\Tests\Common\Mock\MockObserver;
use Guzzle\Tests\Common\Mock\MockSubject;
use Guzzle\Common\Event\Subject;
use Guzzle\Common\Event\EventManager;
use Guzzle\Common\Event\Observer;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class EventManagerTest extends \Guzzle\Tests\GuzzleTestCase implements Observer
{
    /**
     * @covers Guzzle\Common\Event\EventManager::attach
     * @covers Guzzle\Common\Event\EventManager::getAttached
     * @covers Guzzle\Common\Event\EventManager::__construct
     * @covers Guzzle\Common\Event\EventManager::getSubject
     * @covers Guzzle\Common\Event\EventManager::getPriority
     */
    public function testAttachesObservers()
    {
        $observer = new MockObserver();
        $mock = new MockSubject();
        $subject = new EventManager($mock);
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());
        $this->assertEquals($mock, $subject->getSubject());

        // A single observer can only be attached once
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());

        // Ensure that closures can be attached alongside other Observers
        $closure = $subject->attach(function($subject, $event, $context) {
            return true;
        }, -10);

        $this->assertInstanceOf('Closure', $closure);
        $this->assertEquals(array($observer, $closure), $subject->getAttached());

        $this->assertEquals(0, $subject->getPriority($observer));
        $this->assertEquals(-10, $subject->getPriority($closure));
        $this->assertNull($subject->getPriority(new \stdClass()));
        $this->assertNull($subject->getPriority('abc'));
    }

    /**
     * @covers Guzzle\Common\Event\EventManager::getAttached
     */
    public function testRetrievesAttachedObserversByName()
    {
        $observer = new MockObserver();
        $subject = new EventManager(new MockSubject());
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());
        $this->assertEquals(array($observer), $subject->getAttached('Guzzle\Tests\Common\Mock\MockObserver'));
    }

    /**
     * @covers Guzzle\Common\Event\EventManager::detach
     * @covers Guzzle\Common\Event\EventManager::getAttached
     */
    public function testDetachesObservers()
    {
        $observer = new MockObserver();
        $subject = new EventManager(new MockSubject());
        $this->assertEquals($observer, $subject->detach($observer));
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());
        $this->assertEquals($observer, $subject->detach($observer));
        $this->assertEquals(array(), $subject->getAttached());

        // Now detach with more than one observer
        $subject->attach($this);
        $subject->attach($observer);
        $subject->detach($this);
        $this->assertEquals(array($observer), $subject->getAttached());
    }

    /**
     * @covers Guzzle\Common\Event\EventManager::detachAll
     */
    public function testDetachesAllObservers()
    {
        $observer = new MockObserver();
        $subject = new EventManager(new MockSubject());
        $this->assertEquals(array(), $subject->detachAll($observer));
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());
        $this->assertEquals(array($observer), $subject->detachAll($observer));
        $this->assertEquals(array(), $subject->getAttached());
    }

    /**
     * @covers Guzzle\Common\Event\EventManager::hasObserver
     */
    public function testHasObserver()
    {
        $observer = new MockObserver();
        $subject = new EventManager(new MockSubject());
        $this->assertFalse($subject->hasObserver($observer));
        $this->assertFalse($subject->hasObserver('Guzzle\Tests\Common\Mock\MockObserver'));
        $subject->attach($observer);
        $this->assertTrue($subject->hasObserver($observer));
        $this->assertTrue($subject->hasObserver('Guzzle\Tests\Common\Mock\MockObserver'));
    }

    /**
     * @covers Guzzle\Common\Event\EventManager::notify
     * @covers Guzzle\Common\Event\EventManager::notifyObserver
     * @covers Guzzle\Common\Event\EventManager::attach
     */
    public function testNotifiesObserversWithActionEventsAndNormalEvents()
    {
        $priorities = array(10, 0, 999, 0, -10);

        $observers = array(
            new MockObserver(),
            new MockObserver(),
            new MockObserver(),
            new MockObserver(),
            new MockObserver()
        );
        
        $sub = new MockSubject();
        $subject = new EventManager($sub);

        foreach ($observers as $i => $o) {
            $subject->attach($o, $priorities[$i]);
        }

        // Make sure that the observers were properly sorted
        $attached = $subject->getAttached();
        $this->assertEquals(5, count($attached));
        $this->assertSame($attached[0], $observers[2]);
        $this->assertSame($attached[1], $observers[0]);
        $this->assertSame($attached[2], $observers[1]);
        $this->assertSame($attached[3], $observers[3]);
        $this->assertSame($attached[4], $observers[4]);

        $this->assertEquals(array(true, true, true, true, true), $subject->notify('test', 'context'));

        foreach ($observers as $o) {
            $this->assertEquals('test', $o->event);
            $this->assertEquals('context', $o->context);
            $this->assertEquals(2, $o->notified);
            $this->assertEquals($sub, $o->subject);
            // Should have gotten the attach event and the subsequent test event
            $this->assertEquals(array('event.attach', 'test'), $o->events);
        }

        // Make sure the it will update them again
        $this->assertEquals(array(true, true, true, true, true), $subject->notify('test'));
        foreach ($observers as $o) {
            $this->assertEquals('test', $o->event);
            $this->assertEquals(null, $o->context);
            $this->assertEquals(3, $o->notified);
            $this->assertEquals($sub, $o->subject);
            // Did it get another test event?
            $this->assertEquals(array('event.attach', 'test', 'test'), $o->events);
        }
    }

    /**
     * @covers Guzzle\Common\Event\EventManager::notify
     */
    public function testNotifiesObserversUntil()
    {
        $sub = new MockSubject();
        $subject = new EventManager($sub);

        $observer1 = new MockObserver();
        $observer2 = new MockObserver();
        $observer3 = new MockObserver();
        $observer4 = new MockObserver();
        
        $subject->attach($observer1);
        $subject->attach($observer2);
        $subject->attach($observer3);
        $subject->attach($observer4);

        $this->assertEquals(array(true), $subject->notify('test', null, true));
    }

    /**
     * @covers Guzzle\Common\Event\EventManager
     */
    public function testCanAttachClosures()
    {
        $mock = new MockSubject();
        $subject = new EventManager($mock);

        try {
            $apples = 'oranges';
            $subject->attach($apples);
            $this->fail('Expected exception when adding oberser that was not a Closure or Observer');
        } catch (\InvalidArgumentException $e) {
        }

        $out = '';

        $closureA = $subject->attach(function($subject, $event, $context) use (&$out) {
            $out .= 'A: ' . $event . ' - ' . $context . "\n";
            return true;
        }, 0);

        $closureB = $subject->attach(function($subject, $event, $context) use (&$out) {
            $out .= 'B: ' . $event . ' - ' . $context . "\n";
            return true;
        }, 1);

        $this->assertEquals(array($closureB, $closureA), $subject->getAttached());

        $subject->notify('test', 'context');

        // The events should have been received in the following order by the 
        // observers
        $this->assertEquals(trim(implode("\n", array(
            'A: event.attach - ',
            'B: event.attach - ',
            'B: test - context',
            'A: test - context',
        ))), trim($out));
    }
    
    /**
     * {@inheritdoc}
     */
    public function update(Subject $subject, $event, $context = null)
    {
        return;
    }
}