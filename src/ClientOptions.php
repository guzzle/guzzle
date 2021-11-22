<?php

namespace GuzzleHttp;

/**
 * This class contains a list of built-in Guzzle client options.
 *
 * More documentation for each option can be found at http://guzzlephp.org/.
 *
 * @link http://docs.guzzlephp.org/en/v6/quickstart.html
 */
final class ClientOptions
{
    /**
     * base_uri: (string|UriInterface) Base URI of the client that is merged
     * into relative URIs. Can be a string or instance of UriInterface.
     */
    public const BASE_URI = 'base_uri';

    /**
     * handler: (callable) Function that transfers HTTP requests over the
     * wire. The function is called with a Psr7\Http\Message\RequestInterface
     * and array of transfer options, and must return a
     * GuzzleHttp\Promise\PromiseInterface that is fulfilled with a
     * Psr7\Http\Message\ResponseInterface on success.
     * If no handler is provided, a default handler will be created
     * that enables all request options by attaching all the default middleware
     * to the handler.
     */
    public const HANDLER = 'handler';
}