<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Message\Post\PostBodyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prepares requests with a body before sending
 */
class PrepareRequestBodySubscriber implements EventSubscriberInterface
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

        // Determine if the Expect header should be used
        if (!$request->hasHeader('Expect')) {
            $addExpect = false;
            if (null !== ($expect = $request->getConfig()['expect'])) {
                $size = $body->getSize();
                $addExpect = $size === null ? true : $size > $expect;
            } elseif (!$body->isSeekable()) {
                // Always add the Expect 100-Continue header if the body cannot be rewound
                $addExpect = true;
            }
            if ($addExpect) {
                $request->setHeader('Expect', '100-Continue');
            }
        }

        // Never send a Transfer-Encoding: chunked and Content-Length header in the same request
        if ((string) $request->getHeader('Transfer-Encoding') == 'chunked') {
            $request->removeHeader('Content-Length');
        }
    }
}
