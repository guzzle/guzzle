<?php

namespace Guzzle\Common;

use Symfony\Component\EventDispatcher\Event as SymfonyEvent;

/**
 * Default event for Guzzle notifications
 */
class Event extends SymfonyEvent implements ToArrayInterface, \ArrayAccess, \IteratorAggregate
{
    use HasData;
    
    /**
     * @param array $context Contextual information
     */
    public function __construct(array $context = array())
    {
        $this->data = $context;
    }
}
