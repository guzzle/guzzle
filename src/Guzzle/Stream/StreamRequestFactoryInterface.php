<?php

namespace Guzzle\Stream;

use Guzzle\Http\Message\RequestInterface;

/**
 * Interface used for creating streams from requests
 */
interface StreamRequestFactoryInterface
{
    /**
     * Create a stream based on a request object
     *
     * @param RequestInterface $request        Base the stream on a request
     * @param array            $contextOptions Custom context options to merge into the default context options
     *
     * @return resource Returns a stream resource that can be used like fopen resources
     * @throws \Guzzle\Common\Exception\RuntimeException if the stream cannot be opened or an error occurs
     */
    public function fromRequest(RequestInterface $request, array $contextOptions = null);
}
