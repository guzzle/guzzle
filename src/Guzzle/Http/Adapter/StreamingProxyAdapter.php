<?php

namespace Guzzle\Http\Adapter;

/**
 * Sends all streaming requests to a streaming compatible adapter while sending all other requests to a default
 * adapter. This, for example, could be useful for taking advantage of the performance benefits of the CurlAdapter
 * while still supporing true streaming through the StreamAdapter.
 */
class StreamingProxyAdapter implements AdapterInterface
{
    protected $defaultAdapter;
    protected $streamingAdapter;

    /**
     * @param AdapterInterface $defaultAdapter   Adapter used for non-streaming responses
     * @param AdapterInterface $streamingAdapter Adapter used for streaming responses
     */
    public function __construct(AdapterInterface $defaultAdapter, AdapterInterface $streamingAdapter)
    {
        $this->defaultAdapter = $defaultAdapter;
        $this->streamingAdapter = $streamingAdapter;
    }

    public function send(array $requests)
    {
        $streaming = $default = array();

        foreach ($requests as $request) {
            if ($request->getTransferOptions()['streaming']) {
                $streaming[] = $request;
            } else {
                $default[] = $request;
            }
        }

        if ($default) {
            $result = $this->defaultAdapter->send($default);
            if ($streaming) {
                $result->addAll($this->streamingAdapter->send($streaming));
            }
            return $result;
        } elseif ($streaming) {
            return $this->streamingAdapter->send($streaming);
        }
    }
}
