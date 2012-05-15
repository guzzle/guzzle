<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\CookieJar\CookieJarInterface;
use Guzzle\Http\Parser\ParserRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds, extracts, and persists cookies between HTTP requests
 */
class CookiePlugin implements EventSubscriberInterface
{
    /**
     * @var CookieJarInterface
     */
    protected $jar;

    /**
     * Create a new CookiePlugin
     *
     * @param CookieJarInterface $storage Object used to persist cookies
     */
    public function __construct(CookieJarInterface $storage)
    {
        $this->jar = $storage;
    }

    /**
     * Clears temporary cookies
     */
    public function __destruct()
    {
        $this->clearTemporaryCookies();
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
     * Add cookies to a request based on the destination of the request and
     * the cookies stored in the storage backend.  Any previously set cookies
     * will be removed.
     *
     * @param RequestInterface $request Request to add cookies to.  If the
     *      request object already has a cookie header, then no further cookies
     *      will be added.
     *
     * @return array Returns an array of the cookies that were added
     */
    public function addCookies(RequestInterface $request)
    {
        $request->removeHeader('Cookie');
        // Find cookies that match this request
        $cookies = $this->jar->getCookies($request->getHost(), $request->getPath());
        $match = false;

        if ($cookies) {
            foreach ($cookies as $cookie) {
                $match = true;
                // If a port restriction is set, validate the port
                if (!empty($cookie['port'])) {
                    if (!in_array($request->getPort(), $cookie['port'])) {
                        $match = false;
                    }
                }
                // Validate the secure flag
                if ($cookie['secure']) {
                    if ($request->getScheme() != 'https') {
                        $match = false;
                    }
                }
                // If this request is eligible for the cookie, then merge it in
                if ($match) {
                    $request->addCookie($cookie['cookie'][0], isset($cookie['cookie'][1]) ? $cookie['cookie'][1] : null);
                }
            }
        }

        return $match && $cookies ? $cookies : array();
    }

    /**
     * Extracts cookies from an HTTP Response object, looking for Set-Cookie:
     * and Set-Cookie2: headers and persists them to the cookie storage.
     *
     * @param Response $response
     */
    public function extractCookies(Response $response)
    {
        if (!$cookie = $response->getSetCookie()) {
            return array();
        }

        $cookieData = array();
        $parser = ParserRegistry::get('cookie');
        foreach ($cookie as $c) {

            $request = $response->getRequest();

            if ($request) {
                $cdata = $parser->parseCookie($c, $request->getHost(), $request->getPath());
            } else {
                $cdata = $parser->parseCookie($c);
            }

            //@codeCoverageIgnoreStart
            if (!$cdata) {
                continue;
            }
            //@codeCoverageIgnoreEnd

            $cookies = array();
            // Break up cookie v2 into multiple cookies
            if (count($cdata['cookies']) == 1) {
                $cdata['cookie'] = array(key($cdata['cookies']), current($cdata['cookies']));
                unset($cdata['cookies']);
                $cookies = array($cdata);
            } else {
                foreach ($cdata['cookies'] as $key => $cookie) {
                    $row = $cdata;
                    unset($row['cookies']);
                    $row['cookie'] = array($key, $cookie);
                    $cookies[] = $row;
                }
            }

            if (count($cookies)) {
                foreach ($cookies as &$c) {
                    $this->jar->save($c);
                    $cookieData[] = $c;
                }
            }
        }

        return $cookieData;
    }

    /**
     * Clear cookies currently held in the Cookie storage.
     *
     * Invoking this method without arguments will empty the whole Cookie
     * storage.  If given a $domain argument only cookies belonging to that
     * domain will be removed. If given a $domain and $path argument, cookies
     * belonging to the specified path within that domain are removed. If given
     * all three arguments, then the cookie with the specified name, path and
     * domain is removed.
     *
     * @param string $domain (optional) Set to clear only cookies matching a domain
     * @param string $path (optional) Set to clear only cookies matching a domain and path
     * @param string $name (optional) Set to clear only cookies matching a domain, path, and name
     *
     * @return int Returns the number of deleted cookies
     */
    public function clearCookies($domain = null, $path = null, $name = null)
    {
        return $this->jar->clear(str_replace(array('http://', 'https://'), '', $domain), $path, $name);
    }

    /**
     * Discard all temporary cookies.
     *
     * Scans for all cookies in the storage with either no expire field or a
     * true discard flag. To be called when the user agent shuts down according
     * to RFC 2965.
     *
     * @return int Returns the number of deleted cookies
     */
    public function clearTemporaryCookies()
    {
        return $this->jar->clearTemporary();
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
            $this->addCookies($request);
        }
    }

    /**
     * Extract cookies from a sent request
     *
     * @param Event $event
     */
    public function onRequestSent(Event $event)
    {
        $this->extractCookies($event['response']);
    }

    /**
     * Extract cookies from a redirect response
     *
     * @param Event $event
     */
    public function onRequestReceiveStatusLine(Event $event)
    {
        if ($event['previous_response']) {
            $this->extractCookies($event['previous_response']);
        }
    }
}
