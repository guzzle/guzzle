<?php

namespace Guzzle\Http;

use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Exception\RequestException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Throws exceptions when a 4xx or 5xx response is received
 */
class HttpErrorPlugin implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['request.after_send' => 'onRequestAfterSend'];
    }

    /**
     * Throw a RequestException on an HTTP protocol error
     *
     * @param RequestAfterSendEvent $event Emitted event
     * @throws RequestException
     */
    public function onRequestAfterSend(RequestAfterSendEvent $event)
    {
        if ($event->hasResponse()) {
            $response = $event->getResult();
            // Throw an exception for an unsuccessful response
            if (($response->isClientError() || $response->isServerError()) && $response->getStatusCode() !== 304) {
                $event->setResult(RequestException::create($event->getRequest(), $response));
            }
        }
    }
}
