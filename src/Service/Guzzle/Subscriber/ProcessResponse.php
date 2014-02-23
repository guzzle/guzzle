<?php

namespace GuzzleHttp\Service\Guzzle\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Service\ProcessEvent;

/**
 * Subscriber used to create response models based on an HTTP response and
 * a service description.
 */
class ProcessResponse implements SubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['process' => ['onProcess']];
    }

    public function onProcess(ProcessEvent $event)
    {

    }
}
