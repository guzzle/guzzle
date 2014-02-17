<?php

namespace GuzzleHttp\Subscriber\MessageIntegrity;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Event\HeadersEvent;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Verifies the message integrity of a response only after the entire response body has been read
 */
class StreamingResponseIntegritySubscriber implements SubscriberInterface
{
    private $hash;
    private $header;

    public function __construct($header, HashInterface $hash)
    {
        $this->header = $header;
        $this->hash = $hash;
    }

    public static function getSubscribedEvents()
    {
        return ['headers' => ['onRequestGotHeaders', -1]];
    }

    public function onRequestGotHeaders(HeadersEvent $event)
    {
        $response = $event->getResponse();
        if (!$this->canValidate($response)) {
            return;
        }

        $request = $event->getRequest();
        $expected = (string) $response->getHeader($this->header);
        $response->setBody(new ReadIntegrityStream(
            $response->getBody(),
            $this->hash,
            function ($result) use ($request, $response, $expected) {
                if ($expected !== $result) {
                    throw new MessageIntegrityException(
                        sprintf(
                            '%s message integrity check failure. Expected "%s" but got "%s"',
                            $this->header, $expected, $result
                        ),
                        $request,
                        $response
                    );
                }
            }
        ));
    }

    private function canValidate(ResponseInterface $response)
    {
        if (!($body = $response->getBody())) {
            return false;
        } elseif (!$response->hasHeader($this->header)) {
            return false;
        } elseif ($response->hasHeader('Transfer-Encoding') ||
            $response->hasHeader('Content-Encoding')
        ) {
            // Currently does not support un-gzipping or inflating responses
            return false;
        }

        return true;
    }
}
