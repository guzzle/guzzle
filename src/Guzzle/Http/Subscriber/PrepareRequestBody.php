<?php

namespace Guzzle\Http\Subscriber;

use Guzzle\Common\EventSubscriberInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Message\Post\PostBodyInterface;
use Guzzle\Stream\StreamInterface;

/**
 * Prepares requests with a body before sending
 */
class PrepareRequestBody implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['request.before_send' => ['onRequestBeforeSend', -1]];
    }

    public function onRequestBeforeSend(RequestBeforeSendEvent $event)
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
        if ((string) $request->getHeader('Transfer-Encoding') == 'chunked') {
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
