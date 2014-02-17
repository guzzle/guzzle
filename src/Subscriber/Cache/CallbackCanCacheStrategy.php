<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Determines if a request can be cached using a callback
 */
class CallbackCanCacheStrategy extends DefaultCanCacheStrategy
{
    /** @var callable Callback for request */
    protected $requestCallback;

    /** @var callable Callback for response */
    protected $responseCallback;

    /**
     * @param callable $requestCallback  Callable method to invoke for requests
     * @param callable $responseCallback Callable method to invoke for responses
     */
    public function __construct(callable $requestCallback = null, callable $responseCallback = null)
    {
        $this->requestCallback = $requestCallback;
        $this->responseCallback = $responseCallback;
    }

    public function canCacheRequest(RequestInterface $request)
    {
        return $this->requestCallback
            ? call_user_func($this->requestCallback, $request)
            : parent::canCacheRequest($request);
    }

    public function canCacheResponse(ResponseInterface $response)
    {
        return $this->responseCallback
            ? call_user_func($this->responseCallback, $response)
            : parent::canCacheResponse($response);
    }
}
