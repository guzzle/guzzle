<?php

namespace Guzzle\Subscriber\MessageIntegrity;

use Guzzle\Common\EventSubscriberInterface;
use Guzzle\Http\Event\BeforeEvent;

/**
 * Verifies the message integrity of a response after all of the data has been received
 */
class MessageIntegritySubscriber implements EventSubscriberInterface
{
    private $full;
    private $streaming;

    /**
     * Creates a new plugin that validates the Content-MD5 of responses
     *
     * @return MessageIntegritySubscriber
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
        $this->full = new FullResponseIntegritySubscriber($header, $hash, $sizeCutoff);
        $this->streaming = new StreamingResponseIntegritySubscriber($header, $hash);
    }

    public static function getSubscribedEvents()
    {
        return ['before' => ['onRequestBeforeSend']];
    }

    public function onRequestBeforeSend(BeforeEvent $event)
    {
        if ($event->getRequest()->getConfig()->get('streaming')) {
            $event->getRequest()->getEmitter()->addSubscriber($this->streaming);
        } else {
            $event->getRequest()->getEmitter()->addSubscriber($this->full);
        }
    }
}
