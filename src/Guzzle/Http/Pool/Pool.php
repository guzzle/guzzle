<?php

namespace Guzzle\Http\Pool;

use Guzzle\Http\Adapter\BatchAdapterInterface;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\FutureResponseInterface;

class Pool implements PoolInterface
{
    /** @var ClientInterface Client used to send requests */
    private $client;

    /** @var int Number of requests to send in parallel */
    private $concurrency;

    /**
     * @param ClientInterface $client      Client used to send requests
     * @param int             $concurrency Number of requests to send in parallel
     */
    public function __construct(ClientInterface $client, $concurrency = 25)
    {
        $this->client = $client;
        $this->concurrency = max(1, $concurrency);
    }

    public function send($requests)
    {
        $queue = [];
        foreach ($requests as $request) {
            $request->getConfig()['future'] = true;
            $response = $this->client->send($request);
            // Not all clients have future or batch support
            if (!($response instanceof FutureResponseInterface) ||
                !($response->getAdapter() instanceOf BatchAdapterInterface)
            ) {
                yield $request => $response;
                continue;
            }
            $queue[] = $response;
            if (count($queue) > $this->concurrency) {
                foreach ($this->sendQueue($queue) as $request => $response) {
                    yield $request => $response;
                }
                $queue = [];
            }
        }

        if ($queue) {
            foreach ($this->sendQueue($queue) as $request => $response) {
                yield $request => $response;
            }
        }
    }

    private function sendQueue(array $responses)
    {
        $adapters = new \SplObjectStorage();
        foreach ($responses as $future) {
            $adapter = $future->getAdapter();
            if (!isset($adapters[$adapter])) {
                $adapters[$adapter] = [$future->getTransaction()];
            } else {
                $list = $adapters[$adapter];
                $list[] = $future->getTransaction();
                $adapters[$adapter] = $list;
            }
        }

        foreach ($adapters as $adapter) {
            $adapter->batch($adapters[$adapter]);
        }

        foreach ($responses as $future) {
            $transaction = $future->getTransaction();
            yield $transaction->getRequest() => $transaction->getResponse();
        }
    }
}
