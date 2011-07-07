<?php

namespace Guzzle\Tests\Common\Mock;

use Guzzle\Common\Event\Subject;
use Guzzle\Common\Event\EventManager;
use Guzzle\Common\Event\Observer;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockObserver implements Observer
{
    public $notified = 0;
    public $subject;
    public $context;
    public $event;
    public $log = array();
    public $logByEvent = array();
    public $events = array();

   /**
     * {@inheritdoc}
     */
    public function update(Subject $subject, $event, $context = null)
    {
        $this->notified++;
        $this->subject = $subject;
        $this->context = $context;
        $this->event = $event;
        $this->events[] = $event;
        $this->log[] = array($event, $context);
        $this->logByEvent[$event] = $context;
        
        return true;
    }
}