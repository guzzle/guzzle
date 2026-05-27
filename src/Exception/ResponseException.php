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
        parent::__construct($message, $request, $response, $previous, $handlerContext);
        $this->response = $response;
    }

    /**
     * Get the associated response.
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * @deprecated since 8.0. Response exceptions always have a response. Use instanceof ResponseException instead.
     */
    public function hasResponse(): bool
    {
        \trigger_deprecation('guzzlehttp/guzzle', '8.0', '%s::hasResponse() is deprecated and will be removed in 9.0. Use instanceof %s instead.', static::class, self::class);

        return true;
    }
}
