<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Exception thrown when a request fails after response headers are received.
 */
class ResponseException extends RequestException
{
    private ResponseInterface $response;

    public function __construct(
        string $message,
        RequestInterface $request,
        ResponseInterface $response,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $request, $response->getStatusCode(), $previous);
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
