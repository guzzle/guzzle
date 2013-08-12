<?php

namespace Guzzle\Plugin\MessageIntegrity;

use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Stream\Stream;
use Guzzle\Stream\StreamInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Verifies the message integrity of a response after all of the data has been recieved
 */
class ProactiveResponseIntegrityPlugin implements EventSubscriberInterface
{
    private $hash;
    private $header;
    private $sizeCutoff;

    public function __construct($header, HashInterface $hash, $sizeCutoff = null)
    {
        $this->header = $header;
        $this->hash = $hash;
        $this->sizeCutoff = $sizeCutoff;
    }

    public static function getSubscribedEvents()
    {
        return ['request.after_send' => ['onRequestAfterSend', -1]];
    }

    public function onRequestAfterSend(RequestAfterSendEvent $event)
    {
        if ($this->canValidate($event->getResponse())) {
            $response = $event->getResponse();
            $this->matchesHash(
                $event,
                (string) $response->getHeader($this->header),
                $response->getBody()
            );
        }
    }

    private function canValidate(ResponseInterface $response)
    {
        if (!($body = $response->getBody())) {
            return false;
        } elseif (!$response->hasHeader($this->header)) {
            return false;
        } elseif ($response->hasHeader('Transfer-Encoding')) {
            // Currently does not support un-gzipping or inflating responses
            return false;
        } elseif (!$body->isSeekable()) {
            return false;
        } elseif ($this->sizeCutoff !== null && $body->getSize() > $this->sizeCutoff) {
            return false;
        }

        return true;
    }

    private function matchesHash(RequestAfterSendEvent $event, $hash, StreamInterface $body)
    {
        $body->rewind();
        while (!$body->eof()) {
            $this->hash->update($body->read(16384));
        }

        $result = base64_encode($this->hash->complete());
        if ($hash !== $result) {
            $event->intercept(new MessageIntegrityException(
                sprintf(
                    '%s message integrity check failure. Expected "%s" but got "%s"',
                    $this->header, $hash, $result
                ),
                $event->getRequest(),
                $event->getResponse()
            ));
        }
    }
}
