<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common;

use \Guzzle\Tests\Common\Mock\MockObserver,
    \Guzzle\Tests\Common\Mock\MockSubject,
    \Guzzle\Common\Subject\Subject,
    \Guzzle\Common\Subject\SubjectMediator,
    \Guzzle\Common\Subject\Observer;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SubjectMediatorTest extends \Guzzle\Tests\GuzzleTestCase implements Observer
{
    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::attach
     * @covers Guzzle\Common\Subject\SubjectMediator::getAttached
     * @covers Guzzle\Common\Subject\SubjectMediator::__construct
     * @covers Guzzle\Common\Subject\SubjectMediator::getSubject
     */
    public function testAttach()
    {
        $observer = new MockObserver();
        $mock = new MockSubject();
        $subject = new SubjectMediator($mock, array($observer));
        $this->assertEquals(array($observer), $subject->getAttached());
        $this->assertEquals($mock, $subject->getSubject());

        // A single observer can only be attached once
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());
    }

    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::getAttached
     */
    public function testGetAttachedByName()
    {
        $observer = new MockObserver();
        $subject = new SubjectMediator(new MockSubject());
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());
        $this->assertEquals(array($observer), $subject->getAttached('Guzzle\Tests\Common\Mock\MockObserver'));
    }

    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::detach
     * @covers Guzzle\Common\Subject\SubjectMediator::getAttached
     * @depends testAttach
     */
    public function testDetach()
    {
        $observer = new MockObserver();
        $subject = new SubjectMediator(new MockSubject());
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
     * @covers Guzzle\Common\Subject\SubjectMediator::detachAll
     * @depends testAttach
     */
    public function testDetachAll()
    {
        $observer = new MockObserver();
        $subject = new SubjectMediator(new MockSubject());
        $this->assertEquals(array(), $subject->detachAll($observer));
        $subject->attach($observer);
        $this->assertEquals(array($observer), $subject->getAttached());
        $this->assertEquals(array($observer), $subject->detachAll($observer));
        $this->assertEquals(array(), $subject->getAttached());
    }

    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::getState
     */
    public function testGetState()
    {
        $subject = new SubjectMediator(new MockSubject());
        $this->assertNull($subject->getState());
        $subject->notify('new_state');
        $this->assertEquals('new_state', $subject->getState());
    }

    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::getContext
     */
    public function testGetContext()
    {
        $subject = new SubjectMediator(new MockSubject());
        $this->assertNull($subject->getContext());
        $subject->notify('new_state', 'new_context');
        $this->assertEquals('new_context', $subject->getContext());
    }

    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::hasObserver
     * @depends testAttach
     */
    public function testHasObserver()
    {
        $observer = new MockObserver();
        $subject = new SubjectMediator(new MockSubject());
        $this->assertFalse($subject->hasObserver($observer));
        $this->assertFalse($subject->hasObserver('Guzzle\Tests\Common\Mock\MockObserver'));
        $subject->attach($observer);
        $this->assertTrue($subject->hasObserver($observer));
        $this->assertTrue($subject->hasObserver('Guzzle\Tests\Common\Mock\MockObserver'));
    }

    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::notify
     */
    public function testNotify()
    {
        $observer = new MockObserver();
        $subject = new SubjectMediator(new MockSubject());
        $subject->attach($observer);
        $this->assertEquals(array(true), $subject->notify('test', 'context', false));
        $this->assertEquals('test', $subject->getState());
        $this->assertEquals('context', $subject->getContext());
        $this->assertEquals(1, $observer->notified);
        $this->assertEquals($subject, $observer->subject);

        $this->assertEquals(array(true), $subject->notify(Subject::STATE_UNCHANGED, Subject::STATE_UNCHANGED, false));
        $this->assertEquals(2, $observer->notified);
        $this->assertEquals('test', $subject->getState());
        $this->assertEquals('context', $subject->getContext());

        $this->assertEquals(array(true), $subject->notify(Subject::STATE_UNCHANGED, Subject::STATE_UNCHANGED, true));
        $this->assertEquals(3, $observer->notified);
        $this->assertEquals('test', $subject->getState());
        $this->assertEquals(null, $subject->getContext());
    }

    /**
     * @covers Guzzle\Common\Subject\SubjectMediator::is
     */
    public function testIs()
    {
        $mock = new MockSubject();
        $subject = new SubjectMediator($mock);
        $this->assertTrue($subject->is('Guzzle\Tests\Common\Mock\MockSubject'));
        $this->assertTrue($subject->is($mock));
    }

    /**
     * Implements Observer
     *
     * @param SubjectMediator $subject
     */
    public function update(SubjectMediator $subject)
    {
        return;
    }
}