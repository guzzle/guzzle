<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Event\ListenerAttacherTrait;
use GuzzleHttp\Message\RequestInterface;

/**
 * Converts a sequence of request objects into a transaction.
 * @internal
 */
class TransactionIterator implements \Iterator
{
    use ListenerAttacherTrait;

    /** @var \Iterator */
    private $source;

    /** @var ClientInterface */
    private $client;

    /** @var array Listeners to attach to each request */
    private $eventListeners = [];

    public function __construct(
        $source,
        ClientInterface $client,
        array $options
    ) {
        $this->client = $client;
        $this->eventListeners = $this->prepareListeners(
            $options,
            ['before', 'complete', 'error']
        );
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

        $this->attachListeners($request, $this->eventListeners);

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

    public function rewind()
    {
        if (!($this->source instanceof \Generator)) {
            $this->source->rewind();
        }
    }
}
