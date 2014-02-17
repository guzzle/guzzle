<?php

namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\CouldNotRewindStreamException;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Url\Url;

/**
 * Plugin to implement HTTP redirects. Can redirect like a web browser or using strict RFC 2616 compliance.
 *
 * **Request options**
 *
 * - max_redirects: You can customize the maximum number of redirects allowed per-request using the 'max_redirects'
 *   option on a request's config object.
 * - strict_redirects: You can use strict redirects by setting 'strict_redirects' to true. Strict redirects adhere to
 *   strict RFC compliant redirection (e.g. redirect POST with POST) vs doing what most clients do (e.g. redirect
 *   POST request with a GET request).
 */
class Redirect implements SubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['complete' => ['onRequestSent', -10]];
    }

    /**
     * Rewind the entity body of the request if needed
     *
     * @param RequestInterface $redirectRequest
     * @throws CouldNotRewindStreamException
     */
    public static function rewindEntityBody(RequestInterface $redirectRequest)
    {
        // Rewind the entity body of the request if needed
        if ($redirectRequest->getBody()) {
            $body = $redirectRequest->getBody();
            // Only rewind the body if some of it has been read already, and throw an exception if the rewind fails
            if ($body->tell() && !$body->seek(0)) {
                throw new CouldNotRewindStreamException(
                    'Unable to rewind the non-seekable entity body of the request after redirecting',
                    $redirectRequest
                );
            }
        }
    }

    /**
     * Called when a request receives a redirect response
     *
     * @param CompleteEvent $event Event emitted
     * @throws TooManyRedirectsException
     */
    public function onRequestSent(CompleteEvent $event)
    {
        $request = $event->getRequest();
        $redirectCount = 0;
        $redirectResponse = $response = $event->getResponse();
        $max = $request->getConfig()->get('max_redirects') ?: 5;

        while (substr($redirectResponse->getStatusCode(), 0, 1) == '3' && $redirectResponse->hasHeader('Location')) {
            if (++$redirectCount > $max) {
                throw new TooManyRedirectsException("Will not follow more than {$redirectCount} redirects", $request);
            }
            $redirectRequest = $this->createRedirectRequest($request, $redirectResponse);
            $redirectResponse = $event->getClient()->send($redirectRequest);
        }

        if ($redirectResponse !== $response) {
            $event->intercept($redirectResponse);
        }
    }

    /**
     * Create a redirect request for a specific request object
     *
     * Takes into account strict RFC compliant redirection (e.g. redirect POST with POST) vs doing what most clients do
     * (e.g. redirect POST with GET).
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @return RequestInterface Returns a new redirect request
     * @throws CouldNotRewindStreamException If the body needs to be rewound but cannot
     */
    private function createRedirectRequest(RequestInterface $request, ResponseInterface $response)
    {
        $strict = $request->getConfig()['strict_redirects'];

        // Use a GET request if this is an entity enclosing request and we are not forcing RFC compliance, but rather
        // emulating what all browsers would do. Be sure to disable redirects on the clone.
        $redirectRequest = clone $request;
        $redirectRequest->getEmitter()->removeSubscriber($this);
        if ($request->getBody() && !$strict && $response->getStatusCode() <= 302) {
            $redirectRequest->setMethod('GET');
            $redirectRequest->setBody(null);
        }

        $this->setRedirectUrl($redirectRequest, $response);
        $this->rewindEntityBody($redirectRequest);

        return $redirectRequest;
    }

    /**
     * Set the appropriate URL on the request based on the location header
     *
     * @param RequestInterface  $redirectRequest
     * @param ResponseInterface $response
     */
    private function setRedirectUrl(RequestInterface $redirectRequest, ResponseInterface $response)
    {
        $location = $response->getHeader('Location');
        $location = Url::fromString($location);

        // If the location is not absolute, then combine it with the original URL
        if (!$location->isAbsolute()) {
            $originalUrl = Url::fromString($redirectRequest->getUrl());
            // Remove query string parameters and just take what is present on the redirect Location header
            $originalUrl->getQuery()->clear();
            $location = $originalUrl->combine($location);
        }

        $redirectRequest->setUrl($location);
    }
}
