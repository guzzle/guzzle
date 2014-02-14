<?php

namespace Guzzle\Http\Subscriber;

use Guzzle\Common\EventSubscriberInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Event\BeforeEvent;
use Guzzle\Http\Message\Post\PostBodyInterface;
use Guzzle\Stream\StreamInterface;

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
class PrepareRequestBody implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['before' => ['onRequestBeforeSend', -1]];
    }

    public function onRequestBeforeSend(BeforeEvent $event)
    {
        $request = $event->getRequest();

        // Set the appropriate Content-Type for a request if one is not set and there are form fields
        if (!($body = $request->getBody())) {
            return;
        }

        // Synchronize the POST body with the request's headers
        if ($body instanceof PostBodyInterface) {
            $body->applyRequestHeaders($request);
        }

        $this->addExpectHeader($request, $body);

        // Never send a Transfer-Encoding: chunked and Content-Length header in the same request
        if ($request->getHeader('Transfer-Encoding') == 'chunked') {
            $request->removeHeader('Content-Length');
        }
    }

    protected function addExpectHeader(RequestInterface $request, StreamInterface $body)
    {
        // Determine if the Expect header should be used
        if (!$request->hasHeader('Expect')) {
            $addExpect = false;
            if (null !== ($expect = $request->getConfig()['expect'])) {
                $size = $body->getSize();
                $addExpect = $size === null ? true : $size >= (int) $expect;
            } elseif (!$body->isSeekable()) {
                // Always add the Expect 100-Continue header if the body cannot be rewound
                $addExpect = true;
            }
            if ($addExpect) {
                $request->setHeader('Expect', '100-Continue');
            }
        }
    }
}
