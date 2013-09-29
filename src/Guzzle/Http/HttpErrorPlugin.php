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
        $code = (string) $event->getResponse()->getStatusCode();
        // Throw an exception for an unsuccessful response
        if ($code[0] === '4' || $code[0] === '5') {
            $event->intercept(RequestException::create($event->getRequest(), $event->getResponse()));
        }
    }
}
