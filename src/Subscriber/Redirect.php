<?php
namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\CouldNotRewindStreamException;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Url;

/**
 * Subscriber used to implement HTTP redirects.
 *
 * **Request options**
 *
 * - redirect: Associative array containing the 'max', 'strict', and 'referer'
 *   keys.
 *
 *   - max: Maximum number of redirects allowed per-request
 *   - strict: You can use strict redirects by setting this value to ``true``.
 *     Strict redirects adhere to strict RFC compliant redirection (e.g.,
 *     redirect POST with POST) vs doing what most clients do (e.g., redirect
 *     POST request with a GET request).
 *   - referer: Set to true to automatically add the "Referer" header when a
 *     redirect request is sent.
 */
class Redirect implements SubscriberInterface
{
    public function getEvents()
    {
        return ['complete' => ['onComplete', RequestEvents::REDIRECT_RESPONSE]];
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
            // Only rewind the body if some of it has been read already, and
            // throw an exception if the rewind fails
            if ($body->tell() && !$body->seek(0)) {
                throw new CouldNotRewindStreamException(
                    'Unable to rewind the non-seekable request body after redirecting',
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
    public function onComplete(CompleteEvent $event)
    {
        $response = $event->getResponse();

        if (substr($response->getStatusCode(), 0, 1) != '3'
            || !$response->hasHeader('Location')
        ) {
            return;
        }

        $request = $event->getRequest();
        if ($parentEvent = $request->getConfig()->get('redirect_event')) {
            $parentRequest = $parentEvent->getRequest();
            $config = $parentRequest->getConfig();
        } else {
            $parentRequest = $request;
            $config = $parentRequest->getConfig();
            $config['redirect_event'] = $event;
        }

        $redirectCount = $config['redirect_count'] + 1;
        $config['redirect_count'] = $redirectCount;
        $max = $config->getPath('redirect/max') ?: 5;

        if ($redirectCount > $max) {
            throw new TooManyRedirectsException(
                "Will not follow more than {$redirectCount} redirects",
                $parentRequest
            );
        }

        $event->intercept($event->getClient()->send(
            $this->createRedirectRequest(
                $request,
                $parentRequest,
                $response
            )
        ));
    }

    private function createRedirectRequest(
        RequestInterface $lastRequest,
        RequestInterface $parentRequest,
        ResponseInterface $response
    ) {
        $config = $parentRequest->getConfig();

        // Use a GET request if this is an entity enclosing request and we are
        // not forcing RFC compliance, but rather emulating what all browsers
        // would do. Be sure to disable redirects on the clone.
        $redirectRequest = clone $lastRequest;

        $statusCode = $response->getStatusCode();
        if ($statusCode == 303 ||
            ($statusCode <= 302 && $lastRequest->getBody()
                && !$config->getPath('redirect/strict'))
        ) {
            $redirectRequest->setMethod('GET');
            $redirectRequest->setBody(null);
        }

        $this->setRedirectUrl($redirectRequest, $response);
        $this->rewindEntityBody($redirectRequest);

        // Add the Referer header if it is told to do so and only
        // add the header if we are not redirecting from https to http.
        if ($config->getPath('redirect/referer')
            && ($redirectRequest->getScheme() == 'https'
                || $redirectRequest->getScheme() == $parentRequest->getScheme())
        ) {
            $url = Url::fromString($lastRequest->getUrl());
            $url->setUsername(null);
            $url->setPassword(null);
            $redirectRequest->setHeader('Referer', (string) $url);
        }

        // Prevent the "end" event from being fired multiple times on th
        // original request.
        $redirectRequest->getEmitter()->on('end', function (EndEvent $e) {
            $e->stopPropagation();
        }, 'first');

        return $redirectRequest;
    }

    /**
     * Set the appropriate URL on the request based on the location header
     *
     * @param RequestInterface  $redirectRequest
     * @param ResponseInterface $response
     */
    private function setRedirectUrl(
        RequestInterface $redirectRequest,
        ResponseInterface $response
    ) {
        $location = $response->getHeader('Location');
        $location = Url::fromString($location);

        // Combine location with the original URL if it is not absolute.
        if (!$location->isAbsolute()) {
            $originalUrl = Url::fromString($redirectRequest->getUrl());
            // Remove query string parameters and just take what is present on
            // the redirect Location header
            $originalUrl->getQuery()->clear();
            $location = $originalUrl->combine($location);
        }

        $redirectRequest->setUrl($location);
    }
}
