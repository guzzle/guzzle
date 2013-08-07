<?php

namespace Guzzle\Http\Adapter;

/**
 * Sends all streaming requests to a streaming compatible adapter while sending all other requests to a default
 * adapter. This, for example, could be useful for taking advantage of the performance benefits of the CurlAdapter
 * while still supporing true streaming through the StreamAdapter.
 */
class StreamingProxyAdapter implements AdapterInterface
{
    private $defaultAdapter;
    private $streamingAdapter;

    /**
     * @param AdapterInterface $defaultAdapter   Adapter used for non-streaming responses
     * @param AdapterInterface $streamingAdapter Adapter used for streaming responses
     */
    public function __construct(AdapterInterface $defaultAdapter, AdapterInterface $streamingAdapter)
    {
        $this->defaultAdapter = $defaultAdapter;
        $this->streamingAdapter = $streamingAdapter;
    }

    public function send(Transaction $transaction)
    {
        $streaming = $default = array();

        foreach ($transaction as $request) {
            if ($request->getConfig()['stream']) {
                $streaming[] = $request;
            } else {
                $default[] = $request;
            }
        }

        if (!$streaming) {
            return $this->defaultAdapter->send($transaction);
        }

        $streamingTransaction = new Transaction($transaction->getClient());
        foreach ($streaming as $request) {
            $streamingTransaction[$request] = $transaction[$request];
        }

        $this->streamingAdapter->send($streamingTransaction);

        if ($default) {
            $defaultTransaction = new Transaction($transaction->getClient());
            foreach ($default as $request) {
                $defaultTransaction[$request] = $transaction[$request];
            }
            $streamingTransaction->addAll($this->defaultAdapter->send($defaultTransaction));
        }

        return $streamingTransaction;
    }
}
