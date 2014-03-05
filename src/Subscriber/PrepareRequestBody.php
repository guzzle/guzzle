<?php

namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Post\PostBodyInterface;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Prepares requests with a body before sending
 *
 * **Request Options**
 *
 * - expect: Set to true to enable the "Expect: 100-Continue" header for a
 *   request that send a body. Set to false to disable "Expect: 100-Continue".
 *   Set to a number so that the size of the payload must be greater than the
 *   number in order to send the Expect header. Setting to a number will send
 *   the Expect header for all requests in which the size of the payload cannot
 *   be determined or where the body is not rewindable.
 */
class PrepareRequestBody implements SubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['before' => ['onRequestBeforeSend', -1]];
    }

    public function onRequestBeforeSend(BeforeEvent $event)
    {
        $request = $event->getRequest();

        // Set the appropriate Content-Type for a request if one is not set and
        // there are form fields
        if (!($body = $request->getBody())) {
            return;
        }

        // Synchronize the POST body with the request's headers
        if ($body instanceof PostBodyInterface) {
            $body->applyRequestHeaders($request);
        }

        $this->addExpectHeader($request, $body);

        // Never send a Transfer-Encoding: chunked and Content-Length header in
        // the same request
        if ($request->getHeader('Transfer-Encoding') == 'chunked') {
            $request->removeHeader('Content-Length');
        }
    }

    private function addExpectHeader(
        RequestInterface $request,
        StreamInterface $body
    ) {
        // Determine if the Expect header should be used
        if (!$request->hasHeader('Expect')) {

            $expect = $request->getConfig()['expect'];

            // The expect header is explicitly disabled or using HTTP/2
            if ($expect === false || $request->getProtocolVersion() >= 2) {
                return;
            }

            // The expect header is explicitly enabled
            if ($expect === true) {
                $request->setHeader('Expect', '100-Continue');
                return;
            }

            // By default, send the expect header when the payload is > 1mb
            if ($expect === null) {
                $expect = 1048576;
            }

            // Always add if the body cannot be rewound, the size cannot be
            // determined, or the size is greater than the cutoff threshold
            $size = $body->getSize();
            if ($size === null ||
                $size >= (int) $expect ||
                !$body->isSeekable()
            ) {
                $request->setHeader('Expect', '100-Continue');
            }
        }
    }
}
