<?php

namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Post\PostBodyInterface;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\MetadataStreamInterface;
use GuzzleHttp\Mimetypes;

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
class Prepare implements SubscriberInterface
{
    public function getEvents()
    {
        return ['before' => ['onBefore', RequestEvents::PREPARE_REQUEST]];
    }

    public function onBefore(BeforeEvent $event)
    {
        $request = $event->getRequest();

        // Set the appropriate Content-Type for a request if one is not set and
        // there are form fields
        if (!($body = $request->getBody())) {
            return;
        }

        $this->addContentLength($request, $body);

        if ($body instanceof PostBodyInterface) {
            // Synchronize the POST body with the request's headers
            $body->applyRequestHeaders($request);
        } elseif (!$request->hasHeader('Content-Type')) {
            $this->addContentType($request, $body);
        }

        $this->addExpectHeader($request, $body);
    }

    private function addContentType(
        RequestInterface $request,
        StreamInterface $body
    ) {
        if (!($body instanceof MetadataStreamInterface)) {
            return;
        }

        if (!($uri = $body->getMetadata('uri'))) {
            return;
        }

        // Guess the content-type based on the stream's "uri" metadata value.
        // The file extension is used to determine the appropriate mime-type.
        if ($contentType = Mimetypes::getInstance()->fromFilename($uri)) {
            $request->setHeader('Content-Type', $contentType);
        }
    }

    private function addContentLength(
        RequestInterface $request,
        StreamInterface $body
    ) {
        // Set the Content-Length header if it can be determined, and never
        // send a Transfer-Encoding: chunked and Content-Length header in
        // the same request.
        if ($request->hasHeader('Content-Length')) {
            // Remove transfer-encoding if content-length is set.
            $request->removeHeader('Transfer-Encoding');
            return;
        }

        if ($request->hasHeader('Transfer-Encoding')) {
            return;
        }

        if (null !== ($size = $body->getSize())) {
            $request->setHeader('Content-Length', $size)
                ->removeHeader('Transfer-Encoding');
        } elseif ('1.1' == $request->getProtocolVersion()) {
            // Use chunked Transfer-Encoding if there is no determinable
            // content-length header and we're using HTTP/1.1.
            $request->setHeader('Transfer-Encoding', 'chunked')
                ->removeHeader('Content-Length');
        }
    }

    private function addExpectHeader(
        RequestInterface $request,
        StreamInterface $body
    ) {
        // Determine if the Expect header should be used
        if ($request->hasHeader('Expect')) {
            return;
        }

        $expect = $request->getConfig()['expect'];

        // Return if disabled or if you're not using HTTP/1.1
        if ($expect === false || $request->getProtocolVersion() !== '1.1') {
            return;
        }

        // The expect header is unconditionally enabled
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
        if ($size === null || $size >= (int) $expect || !$body->isSeekable()) {
            $request->setHeader('Expect', '100-Continue');
        }
    }
}
