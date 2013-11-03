<?php

namespace Guzzle\Plugin\MessageIntegrity;

use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Event\RequestEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Verifies the message integrity of a response after all of the data has been received
 */
class MessageIntegrityPlugin implements EventSubscriberInterface
{
    private $full;
    private $streaming;

    /**
     * Creates a new plugin that validates the Content-MD5 of responses
     *
     * @return MessageIntegrityPlugin
     */
    public static function createForContentMd5()
    {
        return new self('Content-MD5', new PhpHash('md5'));
    }

    /**
     * @param string        $header     Header to check
     * @param HashInterface $hash       Hash used to validate the header value
     * @param int           $sizeCutoff Don't validate when size is greater than this number
     */
    public function __construct($header, HashInterface $hash, $sizeCutoff = null)
    {
        $this->full = new FullResponseIntegrityPlugin($header, $hash, $sizeCutoff);
        $this->streaming = new StreamingResponseIntegrityPlugin($header, $hash);
    }

    public static function getSubscribedEvents()
    {
        return [RequestEvents::BEFORE_SEND => 'onRequestBeforeSend'];
    }

    public function onRequestBeforeSend(RequestBeforeSendEvent $event)
    {
        if ($event->getRequest()->getConfig()->get('streaming')) {
            $event->getRequest()->getEventDispatcher()->addSubscriber($this->streaming);
        } else {
            $event->getRequest()->getEventDispatcher()->addSubscriber($this->full);
        }
    }
}
