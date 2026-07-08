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

    final public function __construct(
        string $message,
        RequestInterface $request,
        ResponseInterface $response,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $request, $response->getStatusCode(), $previous);
        $this->response = $response;
    }

    /**
     * Return a new exception instance with an updated response.
     *
     * @param \Throwable|null $previous Previous exception, or the current
     *                                  previous exception when omitted.
     *
     * @return static
     */
    public function withResponse(ResponseInterface $response, ?\Throwable $previous = null): self
    {
        if ($response->getStatusCode() !== $this->response->getStatusCode()) {
            throw new InvalidArgumentException('Cannot replace response with a different status code.');
        }

        return new static(
            $this->getMessage(),
            $this->getRequest(),
            $response,
            $previous ?? $this->getPrevious()
        );
    }

    /**
     * Get the associated response.
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
