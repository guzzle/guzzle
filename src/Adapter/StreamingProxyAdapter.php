<?php

namespace GuzzleHttp\Adapter;

/**
 * Sends streaming requests to a streaming compatible adapter while sending all
 * other requests to a default adapter.
 *
 * This, for example, could be useful for taking advantage of the performance
 * benefits of the CurlAdapter while still supporting true streaming through
 * the StreamAdapter.
 */
class StreamingProxyAdapter implements AdapterInterface
{
    private $defaultAdapter;
    private $streamingAdapter;

    /**
     * @param AdapterInterface $defaultAdapter   Adapter used for non-streaming responses
     * @param AdapterInterface $streamingAdapter Adapter used for streaming responses
     */
    public function __construct(
        AdapterInterface $defaultAdapter,
        AdapterInterface $streamingAdapter
    ) {
        $this->defaultAdapter = $defaultAdapter;
        $this->streamingAdapter = $streamingAdapter;
    }

    public function send(TransactionInterface $transaction)
    {
        return $transaction->getRequest()->getConfig()['stream']
            ? $this->streamingAdapter->send($transaction)
            : $this->defaultAdapter->send($transaction);
    }
}
