<?php

namespace Guzzle\Common;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Trait that implements the methods of HasDispatcherInterface
 */
trait HasDispatcherTrait
{
    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    public function getEventDispatcher()
    {
        if (!$this->eventDispatcher) {
            $this->eventDispatcher = new EventDispatcher();
        }

        return $this->eventDispatcher;
    }
}
