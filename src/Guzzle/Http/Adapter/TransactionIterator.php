<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;

/**
 * Converts a sequence of request objects into a transaction.
 * @internal This class would not exist if PHP 5.5 was prevalent enough and I could use generators.
 */
class TransactionIterator implements \Iterator
{
    /** @var \Iterator */
    private $source;

    /** @var array */
    private $options;

    /** @var ClientInterface */
    private $client;

    public function __construct($source, ClientInterface $client, array $options)
    {
        $this->client = $client;
        $this->options = $options;
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

        if (isset($this->options['before'])) {
            $request->getEmitter()->on('before', $this->options['before'], -255);
        }

        if (isset($this->options['complete'])) {
            $request->getEmitter()->on('complete', $this->options['complete'], -255);
        }

        if (isset($this->options['error'])) {
            $request->getEmitter()->on('error', $this->options['error'], -255);
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
}
