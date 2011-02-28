<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Mock;

use Guzzle\Common\Subject\SubjectMediator;
use Guzzle\Common\Subject\Observer;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockObserver implements Observer
{
    public $notified = 0;
    public $subject;
    public $context;
    public $state;
    public $log = array();
    public $logByState = array();

    public function update(SubjectMediator $subject)
    {
        $this->notified++;
        $this->subject = $subject;
        $this->context = $subject->getContext();
        $this->state = $subject->getState();
        $this->log[] = array($subject->getState(), $subject->getContext());
        $this->logByState[$subject->getState()] = $subject->getContext();
        
        return true;
    }
}