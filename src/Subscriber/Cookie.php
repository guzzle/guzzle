<?php

namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\CookieJar\ArrayCookieJar;
use GuzzleHttp\CookieJar\CookieJarInterface;

/**
 * Adds, extracts, and persists cookies between HTTP requests
 */
class Cookie implements SubscriberInterface
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
            'before' => ['onRequestBeforeSend', 125],
            'complete'  => ['onRequestSent', 125]
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

    public function onRequestBeforeSend(BeforeEvent $event)
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

    public function onRequestSent(CompleteEvent $event)
    {
        $this->cookieJar->addCookiesFromResponse($event->getRequest(), $event->getResponse());
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
