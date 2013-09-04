<?php

namespace Guzzle\Plugin\Cookie;

use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;
use Guzzle\Plugin\Cookie\CookieJar\CookieJarInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds, extracts, and persists cookies between HTTP requests
 */
class CookiePlugin implements EventSubscriberInterface
{
    /** @var CookieJarInterface Cookie cookieJar used to hold cookies */
    protected $cookieJar;

    /**
     * @param CookieJarInterface $cookieJar Cookie jar used to hold cookies. Creates an ArrayCookieJar by default.
     */
    public function __construct(CookieJarInterface $cookieJar = null)
    {
        $this->cookieJar = $cookieJar ?: new ArrayCookieJar();
    }

    public static function getSubscribedEvents()
    {
        return [
            'request.before_send' => ['onRequestBeforeSend', 125],
            'request.after_send'  => ['onRequestSent', 125]
        ];
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

    public function onRequestBeforeSend(RequestBeforeSendEvent $event)
    {
        $event->getRequest()->removeHeader('Cookie');

        // Find cookies that match this request
        if ($matching = $this->cookieJar->getMatchingCookies($event->getRequest())) {
            $cookies = [];
            foreach ($matching as $cookie) {
                $cookies[] = $cookie->getName() . '=' . $this->getCookieValue($cookie->getValue());
            }
            $event->getRequest()->setHeader('Cookie', implode(';', $cookies));
        }
    }

    public function onRequestSent(RequestAfterSendEvent $event)
    {
        $this->cookieJar->addCookiesFromResponse($event->getResponse(), $event->getRequest());
    }

    private function getCookieValue($value)
    {
        // Quote the value if it is not already and contains problematic characters
        if (substr($value, 0, 1) !== '"' && substr($value, -1, 1) !== '"' && strpbrk($value, ';,')) {
            $value = '"' . $value . '"';
        }

        return $value;
    }
}
