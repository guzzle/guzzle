<?php

namespace Guzzle\Http\Pool;

use Guzzle\Http\Adapter\BatchAdapterInterface;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\FutureResponseInterface;
use Guzzle\Http\Message\RequestInterface;

class Pool implements PoolInterface
{
    /** @var ClientInterface Client used to send requests */
    private $client;

    /** @var int Number of requests to send in parallel */
    private $concurrency;

    /** @var array Array of future responses that can be sent in parallel */
    private $queue = [];

    /** @var array Array of future responses that cannot be sent in parallel */
    private $queuedNonBatch = [];

    /** @var array Array of listeners to attach to each sent request */
    private $listeners;

    /**
     * @param ClientInterface $client      Client used to send requests
     * @param int             $concurrency Number of requests to send in parallel
     * @param array           $listeners   Listeners to attach to each request
     */
    public function __construct(ClientInterface $client, $concurrency = 25, $listeners = [])
    {
        $this->client = $client;
        $this->concurrency = max(1, $concurrency);
        $this->listeners = $listeners;
    }

    public function addRequest(RequestInterface $request)
    {
        $request->getConfig()['future'] = true;

        // Add all global listeners
        foreach ($this->listeners as $name => $func) {
            $request->getEventDispatcher()->addListener($name, $func);
        }

        $response = $this->client->send($request);

        // Not all clients have future support
        if ($response instanceof FutureResponseInterface) {
            $this->enqueue($response);
        }
    }

    public function send()
    {
        iterator_count($this->yieldResponses());
    }

    public function yieldResponses()
    {
        // Send all non-batchable responses
        foreach ($this->queuedNonBatch as $response) {
            yield $response->getTransaction()->getRequest() => $response->getTransaction()->getResponse();
        }

        $adapters = $this->prepareAdapterArrays();
        // Yield each batchable request => response
        foreach ($adapters as $adapter) {
            $adapter->batch($adapters[$adapter]);
            foreach ($adapters[$adapter] as $transaction) {
                yield $transaction->getRequest() => $transaction->getResponse();
            }
            unset($adapters[$adapter]);
        }
    }

    /**
     * Returns an object mapping adapters to an array of transactions
     *
     * @return \SplObjectStorage
     */
    private function prepareAdapterArrays()
    {
        $adapters = new \SplObjectStorage();
        foreach ($this->queue as $response) {
            $adapter = $response->getAdapter();
            if (!isset($adapters[$adapter])) {
                $adapters[$adapter] = [$response->getTransaction()];
            } else {
                $list = $adapters[$adapter];
                $list[] = $response->getTransaction();
                $adapters[$adapter] = $list;
            }
        }

        $this->queue = [];

        return $adapters;
    }

    /**
     * Add a future response to the queue
     *
     * @param FutureResponseInterface $response
     */
    private function enqueue(FutureResponseInterface $response)
    {
        if ($response->getAdapter() instanceOf BatchAdapterInterface) {
            $this->queue[] = $response;
        } else {
            $this->queuedNonBatch[] = $response;
        }

        if (count($this->queue) + count($this->queuedNonBatch) > $this->concurrency) {
            $this->send();
        }
    }
}
