<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Exception thrown when a request fails after a response is received.
 */
class ResponseException extends RequestException
{
    private ResponseInterface $response;

    public function __construct(
        string $message,
        RequestInterface $request,
        ResponseInterface $response,
        ?\Throwable $previous = null,
        array $handlerContext = []
    ) {
        parent::__construct($message, $request, $previous, $handlerContext, $response->getStatusCode());
        $this->response = $response;
    }

    /**
     * Get the associated response.
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
