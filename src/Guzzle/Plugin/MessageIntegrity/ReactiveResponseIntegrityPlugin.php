<?php

namespace Guzzle\Plugin\MessageIntegrity;

use Guzzle\Common\Event;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Stream\Stream;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Verifies the message integrity of a response only after the entire response body has been read
 */
class ReactiveResponseIntegrityPlugin implements EventSubscriberInterface
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
        return ['request.got_headers' => ['onRequestGotHeaders', -1]];
    }

    public function onRequestGotHeaders(Event $event)
    {
        if ($this->canValidate($event['response'])) {
            $response = $event['response'];
            $request = $event['request'];
            $expected = (string) $response->getHeader($this->header);
            $header = $this->header;
            $response->setBody(new ReadIntegrityStream(
                $response->getBody(),
                $this->hash,
                function ($result) use ($request, $response, $expected, $header) {
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
        }

        return true;
    }
}
