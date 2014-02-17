<?php

namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Exception\RequestException;

/**
 * Throws exceptions when a 4xx or 5xx response is received
 */
class HttpError implements SubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['complete' => ['onRequestAfterSend']];
    }

    /**
     * Throw a RequestException on an HTTP protocol error
     *
     * @param CompleteEvent $event Emitted event
     * @throws RequestException
     */
    public function onRequestAfterSend(CompleteEvent $event)
    {
        $code = (string) $event->getResponse()->getStatusCode();
        // Throw an exception for an unsuccessful response
        if ($code[0] === '4' || $code[0] === '5') {
            throw RequestException::create($event->getRequest(), $event->getResponse());
        }
    }
}
