<?php
namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Exception\CouldNotRewindStreamException;
use GuzzleHttp\Exception\TooManyRedirectsException;
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
        if ($body = $redirectRequest->getBody()) {
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
        $config = $request->getConfig();

        // Increment the redirect and initialize the redirect state.
        if ($redirectCount = $config['redirect_count']) {
            $config['redirect_count'] = ++$redirectCount;
        } else {
            $config['redirect_scheme'] = $request->getScheme();
            $config['redirect_count'] = $redirectCount = 1;
        }

        $max = $config->getPath('redirect/max') ?: 5;

        if ($redirectCount > $max) {
            throw new TooManyRedirectsException(
                "Will not follow more than {$redirectCount} redirects",
                $request
            );
        }

        $this->modifyRedirectRequest($request, $response);
        $event->retry();
    }

    private function modifyRedirectRequest(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $config = $request->getConfig();

        // Use a GET request if this is an entity enclosing request and we are
        // not forcing RFC compliance, but rather emulating what all browsers
        // would do.
        $statusCode = $response->getStatusCode();
        if ($statusCode == 303 ||
            ($statusCode <= 302 && $request->getBody() && !$config->getPath('redirect/strict'))
        ) {
            $request->setMethod('GET');
            $request->setBody(null);
        }

        $previousUrl = $request->getUrl();
        $this->setRedirectUrl($request, $response);
        $this->rewindEntityBody($request);

        // Add the Referer header if it is told to do so and only
        // add the header if we are not redirecting from https to http.
        if ($config->getPath('redirect/referer')
            && ($request->getScheme() == 'https' || $request->getScheme() == $config['redirect_scheme'])
        ) {
            $url = Url::fromString($previousUrl);
            $url->setUsername(null);
            $url->setPassword(null);
            $request->setHeader('Referer', (string) $url);
        } else {
            $request->removeHeader('Referer');
        }
    }

    /**
     * Set the appropriate URL on the request based on the location header
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     */
    private function setRedirectUrl(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $location = $response->getHeader('Location');
        $location = Url::fromString($location);

        // Combine location with the original URL if it is not absolute.
        if (!$location->isAbsolute()) {
            $originalUrl = Url::fromString($request->getUrl());
            // Remove query string parameters and just take what is present on
            // the redirect Location header
            $originalUrl->getQuery()->clear();
            $location = $originalUrl->combine($location);
        }

        $request->setUrl($location);
    }
}
