<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Message\RequestInterface;

/**
 * Converts a sequence of request objects into a transaction.
 * @internal
 */
class TransactionIterator implements \Iterator
{
    /** @var \Iterator */
    private $source;

    /** @var ClientInterface */
    private $client;

    /** @var array */
    private $eventListeners;

    public function __construct(
        $source, ClientInterface $client,
        array $options
    ) {
        $this->client = $client;
        $this->configureEvents($options);
        if ($source instanceof \Iterator) {
            $this->source = $source;
        } elseif (is_array($source)) {
            $this->source = new \ArrayIterator($source);
        } else {
            throw new \InvalidArgumentException('Expected an Iterator or array');
        }
    }

    public function current()
    {
        $request = $this->source->current();

        if (!$request instanceof RequestInterface) {
            throw new \RuntimeException('All must implement RequestInterface');
        }

        if ($this->eventListeners) {
            $emitter = $request->getEmitter();
            foreach ($this->eventListeners as $eventName => $listener) {
                $emitter->on($eventName, $listener[0], $listener[1]);
            }
        }

        return new Transaction($this->client, $request);
    }

    public function next()
    {
        $this->source->next();
    }

    public function key()
    {
        return $this->source->key();
    }

    public function valid()
    {
        return $this->source->valid();
    }

    public function rewind() {}

    private function configureEvents(array $options)
    {
        static $namedEvents = ['before', 'complete', 'error'];

        foreach ($namedEvents as $event) {
            if (isset($options[$event])) {
                if (is_callable($options[$event])) {
                    $this->eventListeners[$event] = [$options[$event], 0];
                } elseif (is_array($options[$event])) {
                    $this->eventListeners[$event] = $options[$event];
                } else {
                    throw new \InvalidArgumentException('Each event listener '
                        . ' must be a callable or an array containing a '
                        . ' callable and a priority.');
                }
            }
        }
    }
}
