<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Http\CookieJar\CookieJarInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds, extracts, and persists cookies between HTTP requests
 */
class CookiePlugin implements EventSubscriberInterface
{
    /**
     * @var CookieJarInterface Cookie cookieJar used to hold cookies
     */
    protected $cookieJar;

    /**
     * @param CookieJarInterface $cookieJar Cookie jar used to hold cookies
     */
    public function __construct(CookieJarInterface $cookieJar)
    {
        $this->cookieJar = $cookieJar;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.before_send'         => array('onRequestBeforeSend', 100),
            'request.sent'                => array('onRequestSent', 100),
            'request.receive.status_line' => 'onRequestReceiveStatusLine'
        );
    }

    /**
     * Get the cookie cookieJar
     *
     * @return CookieJarInterface
     */
    public function getCookieJar()
    {
        return $this->cookieJar;
    }

    /**
     * Add cookies before a request is sent
     *
     * @param Event $event
     */
    public function onRequestBeforeSend(Event $event)
    {
        $request = $event['request'];
        if (!$request->getParams()->get('cookies.disable')) {
            $request->removeHeader('Cookie');
            // Find cookies that match this request
            foreach ($this->cookieJar->getMatchingCookies($request) as $cookie) {
                $request->addCookie($cookie->getName(), $cookie->getValue());
            }
        }
    }

    /**
     * Extract cookies from a sent request
     *
     * @param Event $event
     */
    public function onRequestSent(Event $event)
    {
        $this->cookieJar->addCookiesFromResponse($event['response']);
    }

    /**
     * Extract cookies from a redirect response
     *
     * @param Event $event
     */
    public function onRequestReceiveStatusLine(Event $event)
    {
        if ($event['previous_response']) {
            $this->cookieJar->addCookiesFromResponse($event['previous_response']);
        }
    }
}
