<?php

namespace Guzzle\Plugin\Mock\RequestMatcher;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;

/**
 * Request handler that matches responses to URIs (either complete or partial).
 */
class UriRequestMatcher implements RequestMatcherInterface
{
    /**
     * Request method.
     *
     * @var string
     */
    protected $method;

    /**
     * URI-response combinations.
     *
     * @var array
     */
    protected $responses = array();

    /**
     * Constructor.
     *
     * @param string $method    Method.
     * @param array  $responses Associative array of complete or partial URIs as the keys and response objects, string
     *                          responses or paths to files as the values
     */
    public function __construct($method = 'GET', array $responses = array())
    {
        $this->method = $method;
        foreach ($responses as $uri => $response) {
            $this->registerUri($uri, $response);
        }
    }

    /**
     * Register a URI.
     *
     * @param string          $uri      URI (either complete or partial)
     * @param Response|string $response Response object, a string response or a path to a file containing a response
     *
     * @return self Reference to the matcher
     */
    public function registerUri($uri, $response)
    {
        if ($response instanceof Response) {
            $this->responses[$uri] = $response;

            return $this;
        }

        if (file_exists($response)) {
            $response = file_get_contents($response);
        }

        $this->responses[$uri] = Response::fromMessage($response);

        return $this;
    }

    public function match(RequestInterface $request)
    {
        if ($request->getMethod() !== $this->method) {
            return null;
        }

        foreach ($this->responses as $uri => $response) {
            if (false !== stripos($request->getUrl(), $uri)) {
                return $response;
            }
        }

        return null;
    }
}
