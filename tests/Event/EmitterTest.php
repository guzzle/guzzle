<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Event\Emitter;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Event\Event;

/**
 * @link https://github.com/symfony/symfony/blob/master/src/Symfony/Component/EventDispatcher/Tests/EventDispatcherTest.php Based on this test.
 */
class EmitterTest extends \PHPUnit_Framework_TestCase
{
    /* Some pseudo events */
    const preFoo = 'pre.foo';
    const postFoo = 'post.foo';
    const preBar = 'pre.bar';
    const postBar = 'post.bar';

    /** @var Emitter */
    private $emitter;
    private $listener;

    protected function setUp()
    {
        $this->emitter = new Emitter();
        $this->listener = new TestEventListener();
    }

    protected function tearDown()
    {
        $this->emitter = null;
        $this->listener = null;
    }

    public function testInitialState()
    {
        $this->assertEquals(array(), $this->emitter->listeners());
    }

    public function testAddListener()
    {
        $this->emitter->on('pre.foo', array($this->listener, 'preFoo'));
        $this->emitter->on('post.foo', array($this->listener, 'postFoo'));
        $this->assertCount(1, $this->emitter->listeners(self::preFoo));
        $this->assertCount(1, $this->emitter->listeners(self::postFoo));
        $this->assertCount(2, $this->emitter->listeners());
    }

    public function testGetListenersSortsByPriority()
    {
        $listener1 = new TestEventListener();
        $listener2 = new TestEventListener();
        $listener3 = new TestEventListener();
        $listener1->name = '1';
        $listener2->name = '2';
        $listener3->name = '3';

        $this->emitter->on('pre.foo', array($listener1, 'preFoo'), -10);
        $this->emitter->on('pre.foo', array($listener2, 'preFoo'), 10);
        $this->emitter->on('pre.foo', array($listener3, 'preFoo'));

        $expected = array(
            array($listener2, 'preFoo'),
            array($listener3, 'preFoo'),
            array($listener1, 'preFoo'),
        );

        $this->assertSame($expected, $this->emitter->listeners('pre.foo'));
    }

    public function testGetAllListenersSortsByPriority()
    {
        $listener1 = new TestEventListener();
        $listener2 = new TestEventListener();
        $listener3 = new TestEventListener();
        $listener4 = new TestEventListener();
        $listener5 = new TestEventListener();
        $listener6 = new TestEventListener();

        $this->emitter->on('pre.foo', [$listener1, 'preFoo'], -10);
        $this->emitter->on('pre.foo', [$listener2, 'preFoo']);
        $this->emitter->on('pre.foo', [$listener3, 'preFoo'], 10);
        $this->emitter->on('post.foo', [$listener4, 'preFoo'], -10);
        $this->emitter->on('post.foo', [$listener5, 'preFoo']);
        $this->emitter->on('post.foo', [$listener6, 'preFoo'], 10);

        $expected = [
            'pre.foo'  => [[$listener3, 'preFoo'], [$listener2, 'preFoo'], [$listener1, 'preFoo']],
            'post.foo' => [[$listener6, 'preFoo'], [$listener5, 'preFoo'], [$listener4, 'preFoo']],
        ];

        $this->assertSame($expected, $this->emitter->listeners());
    }

    public function testDispatch()
    {
        $this->emitter->on('pre.foo', array($this->listener, 'preFoo'));
        $this->emitter->on('post.foo', array($this->listener, 'postFoo'));
        $this->emitter->emit(self::preFoo, new Event());
        $this->assertTrue($this->listener->preFooInvoked);
        $this->assertFalse($this->listener->postFooInvoked);
        $this->assertInstanceOf('GuzzleHttp\Event\EventInterface', $this->emitter->emit(self::preFoo, new Event()));
        $event = new Event();
        $return = $this->emitter->emit(self::preFoo, $event);
        $this->assertSame($event, $return);
    }

    public function testDispatchForClosure()
    {
        $invoked = 0;
        $listener = function () use (&$invoked) {
            $invoked++;
        };
        $this->emitter->on('pre.foo', $listener);
        $this->emitter->on('post.foo', $listener);
        $this->emitter->emit(self::preFoo, new Event());
        $this->assertEquals(1, $invoked);
    }

    public function testStopEventPropagation()
    {
        $otherListener = new TestEventListener();

        // postFoo() stops the propagation, so only one listener should
        // be executed
        // Manually set priority to enforce $this->listener to be called first
        $this->emitter->on('post.foo', array($this->listener, 'postFoo'), 10);
        $this->emitter->on('post.foo', array($otherListener, 'preFoo'));
        $this->emitter->emit(self::postFoo, new Event());
        $this->assertTrue($this->listener->postFooInvoked);
        $this->assertFalse($otherListener->postFooInvoked);
    }

    public function testDispatchByPriority()
    {
        $invoked = array();
        $listener1 = function () use (&$invoked) {
            $invoked[] = '1';
        };
        $listener2 = function () use (&$invoked) {
            $invoked[] = '2';
        };
        $listener3 = function () use (&$invoked) {
            $invoked[] = '3';
        };
        $this->emitter->on('pre.foo', $listener1, -10);
        $this->emitter->on('pre.foo', $listener2);
        $this->emitter->on('pre.foo', $listener3, 10);
        $this->emitter->emit(self::preFoo, new Event());
        $this->assertEquals(array('3', '2', '1'), $invoked);
    }

    public function testRemoveListener()
    {
        $this->emitter->on('pre.bar', [$this->listener, 'preFoo']);
        $this->assertNotEmpty($this->emitter->listeners(self::preBar));
        $this->emitter->removeListener('pre.bar', [$this->listener, 'preFoo']);
        $this->assertEmpty($this->emitter->listeners(self::preBar));
        $this->emitter->removeListener('notExists', [$this->listener, 'preFoo']);
    }

    public function testAddSubscriber()
    {
        $eventSubscriber = new TestEventSubscriber();
        $this->emitter->addSubscriber($eventSubscriber);
        $this->assertNotEmpty($this->emitter->listeners(self::preFoo));
        $this->assertNotEmpty($this->emitter->listeners(self::postFoo));
    }

    public function testAddSubscriberWithPriorities()
    {
        $eventSubscriber = new TestEventSubscriber();
        $this->emitter->addSubscriber($eventSubscriber);

        $eventSubscriber = new TestEventSubscriberWithPriorities();
        $this->emitter->addSubscriber($eventSubscriber);

        $listeners = $this->emitter->listeners('pre.foo');
        $this->assertNotEmpty($this->emitter->listeners(self::preFoo));
        $this->assertCount(2, $listeners);
        $this->assertInstanceOf('GuzzleHttp\Tests\Event\TestEventSubscriberWithPriorities', $listeners[0][0]);
    }

    public function testRemoveSubscriber()
    {
        $eventSubscriber = new TestEventSubscriber();
        $this->emitter->addSubscriber($eventSubscriber);
        $this->assertNotEmpty($this->emitter->listeners(self::preFoo));
        $this->assertNotEmpty($this->emitter->listeners(self::postFoo));
        $this->emitter->removeSubscriber($eventSubscriber);
        $this->assertEmpty($this->emitter->listeners(self::preFoo));
        $this->assertEmpty($this->emitter->listeners(self::postFoo));
    }

    public function testRemoveSubscriberWithPriorities()
    {
        $eventSubscriber = new TestEventSubscriberWithPriorities();
        $this->emitter->addSubscriber($eventSubscriber);
        $this->assertNotEmpty($this->emitter->listeners(self::preFoo));
        $this->assertNotEmpty($this->emitter->listeners(self::postFoo));
        $this->emitter->removeSubscriber($eventSubscriber);
        $this->assertEmpty($this->emitter->listeners(self::preFoo));
        $this->assertEmpty($this->emitter->listeners(self::postFoo));
    }

    public function testEventReceivesEventNameAsArgument()
    {
        $listener = new TestWithDispatcher();
        $this->emitter->on('test', array($listener, 'foo'));
        $this->assertNull($listener->name);
        $this->emitter->emit('test', new Event());
        $this->assertEquals('test', $listener->name);
    }

    /**
     * @see https://bugs.php.net/bug.php?id=62976
     *
     * This bug affects:
     *  - The PHP 5.3 branch for versions < 5.3.18
     *  - The PHP 5.4 branch for versions < 5.4.8
     *  - The PHP 5.5 branch is not affected
     */
    public function testWorkaroundForPhpBug62976()
    {
        $dispatcher = new Emitter();
        $dispatcher->on('bug.62976', new CallableClass());
        $dispatcher->removeListener('bug.62976', function () {});
        $this->assertNotEmpty($dispatcher->listeners('bug.62976'));
    }

    public function testRegistersEventsOnce()
    {
        $this->emitter->once('pre.foo', array($this->listener, 'preFoo'));
        $this->emitter->on('pre.foo', array($this->listener, 'preFoo'));
        $this->assertCount(2, $this->emitter->listeners(self::preFoo));
        $this->emitter->emit(self::preFoo, new Event());
        $this->assertTrue($this->listener->preFooInvoked);
        $this->assertCount(1, $this->emitter->listeners(self::preFoo));
    }

    public function testReturnsEmptyArrayForNonExistentEvent()
    {
        $this->assertEquals([], $this->emitter->listeners('doesnotexist'));
    }
}

class CallableClass
{
    public function __invoke()
    {
    }
}

class TestEventListener
{
    public $preFooInvoked = false;
    public $postFooInvoked = false;

    /* Listener methods */

    public function preFoo(Event $e)
    {
        $this->preFooInvoked = true;
    }

    public function postFoo(Event $e)
    {
        $this->postFooInvoked = true;

        $e->stopPropagation();
    }
}

class TestWithDispatcher
{
    public $name;

    public function foo(Event $e, $name)
    {
        $this->name = $name;
    }
}

class TestEventSubscriber extends TestEventListener implements SubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'pre.foo' => ['preFoo'],
            'post.foo' => ['postFoo']
        ];
    }
}

class TestEventSubscriberWithPriorities extends TestEventListener implements SubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'pre.foo' => ['preFoo', 10],
            'post.foo' => ['postFoo']
        ];
    }
}
