<?php

namespace GuzzleHttp\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Exception when an HTTP error occurs (4xx or 5xx error)
 *
 * @method ResponseInterface getResponse()
 */
class BadResponseException extends RequestException
{
    public function __construct(
        string $message,
        RequestInterface $request,
        ResponseInterface $response,
        \Exception $previous = null,
        array $handlerContext = []
    ) {
        parent::__construct($message, $request, $response, $previous, $handlerContext);
    }

    public function hasResponse(): bool
    {
        return true;
    }
}
