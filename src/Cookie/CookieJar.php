<?php

namespace GuzzleHttp\Cookie;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\ToArrayInterface;

/**
 * Cookie jar that stores cookies an an array
 */
class CookieJar implements CookieJarInterface, ToArrayInterface
{
    /** @var SetCookie[] Loaded cookie data */
    private $cookies = [];

    /** @var bool */
    private $strictMode;

    /**
     * @param bool $strictMode   Set to true to throw exceptions when invalid
     *                           cookies are added to the cookie jar.
     * @param array $cookieArray Array of SetCookie objects or a hash of arrays
     *                           that can be used with the SetCookie constructor
     */
    public function __construct($strictMode = false, $cookieArray = [])
    {
        $this->strictMode = $strictMode;

        foreach ($cookieArray as $cookie) {
            if (!($cookieArray instanceof SetCookie)) {
                $cookie = new SetCookie($cookie);
            }
            $this->setCookie($cookie);
        }
    }

    /**
     * Create a new Cookie jar from an associative array and domain.
     *
     * @param array  $cookies Cookies to create the jar from
     * @param string $domain  Domain to set the cookies to
     *
     * @return self
     */
    public static function fromArray(array $cookies, $domain)
    {
        $cookieJar = new self();
        foreach ($cookies as $name => $value) {
            $cookieJar->setCookie(new SetCookie([
                'Domain'  => $domain,
                'Name'    => $name,
                'Value'   => $value,
                'Discard' => true
            ]));
        }

        return $cookieJar;
    }

    /**
     * Quote the cookie value if it is not already quoted and it contains
     * problematic characters.
     *
     * @param string $value Value that may or may not need to be quoted
     *
     * @return string
     */
    public static function getCookieValue($value)
    {
        if (substr($value, 0, 1) !== '"' &&
            substr($value, -1, 1) !== '"' &&
            strpbrk($value, ';,')
        ) {
            $value = '"' . $value . '"';
        }

        return $value;
    }

    public function toArray()
    {
        return array_map(function (SetCookie $cookie) {
            return $cookie->toArray();
        }, $this->getIterator()->getArrayCopy());
    }

    public function clear($domain = null, $path = null, $name = null)
    {
        if (!$domain) {
            $this->cookies = [];
            return;
        } elseif (!$path) {
            $this->cookies = array_filter(
                $this->cookies,
                function (SetCookie $cookie) use ($path, $domain) {
                    return !$cookie->matchesDomain($domain);
                }
            );
        } elseif (!$name) {
            $this->cookies = array_filter(
                $this->cookies,
                function (SetCookie $cookie) use ($path, $domain) {
                    return !($cookie->matchesPath($path) &&
                        $cookie->matchesDomain($domain));
                }
            );
        } else {
            $this->cookies = array_filter(
                $this->cookies,
                function (SetCookie $cookie) use ($path, $domain, $name) {
                    return !($cookie->getName() == $name &&
                        $cookie->matchesPath($path) &&
                        $cookie->matchesDomain($domain));
                }
            );
        }
    }

    public function clearSessionCookies()
    {
        $this->cookies = array_filter(
            $this->cookies,
            function (SetCookie $cookie) {
                return !$cookie->getDiscard() && $cookie->getExpires();
            }
        );
    }

    public function setCookie(SetCookie $cookie)
    {
        // Only allow cookies with set and valid domain, name, value
        $result = $cookie->validate();
        if ($result !== true) {
            if ($this->strictMode) {
                throw new \RuntimeException('Invalid cookie: ' . $result);
            } else {
                $this->removeCookieIfEmpty($cookie);
                return false;
            }
        }

        // Resolve conflicts with previously set cookies
        foreach ($this->cookies as $i => $c) {

            // Two cookies are identical, when their path, and domain are
            // identical.
            if ($c->getPath() != $cookie->getPath() ||
                $c->getDomain() != $cookie->getDomain() ||
                $c->getName() != $cookie->getName()
            ) {
                continue;
            }

            // The previously set cookie is a discard cookie and this one is
            // not so allow the new cookie to be set
            if (!$cookie->getDiscard() && $c->getDiscard()) {
                unset($this->cookies[$i]);
                continue;
            }

            // If the new cookie's expiration is further into the future, then
            // replace the old cookie
            if ($cookie->getExpires() > $c->getExpires()) {
                unset($this->cookies[$i]);
                continue;
            }

            // If the value has changed, we better change it
            if ($cookie->getValue() !== $c->getValue()) {
                unset($this->cookies[$i]);
                continue;
            }

            // The cookie exists, so no need to continue
            return false;
        }

        $this->cookies[] = $cookie;

        return true;
    }

    public function count()
    {
        return count($this->cookies);
    }

    public function getIterator()
    {
        return new \ArrayIterator(array_values($this->cookies));
    }

    public function extractCookies(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        if ($cookieHeader = $response->getHeader('Set-Cookie', true)) {
            foreach ($cookieHeader as $cookie) {
                $sc = SetCookie::fromString($cookie);
                if (!$sc->getDomain()) {
                    $sc->setDomain($request->getHost());
                }
                $this->setCookie($sc);
            }
        }
    }

    public function addCookieHeader(RequestInterface $request)
    {
        $values = [];
        $scheme = $request->getScheme();
        $host = $request->getHost();
        $path = $request->getPath();

        foreach ($this->cookies as $cookie) {
            if ($cookie->matchesPath($path) &&
                $cookie->matchesDomain($host) &&
                !$cookie->isExpired() &&
                (!$cookie->getSecure() || $scheme == 'https')
            ) {
                $values[] = $cookie->getName() . '='
                    . self::getCookieValue($cookie->getValue());
            }
        }

        if ($values) {
            $request->setHeader('Cookie', implode('; ', $values));
        }
    }

    /**
     * If a cookie already exists and the server asks to set it again with a
     * null value, the cookie must be deleted.
     *
     * @param SetCookie $cookie
     */
    private function removeCookieIfEmpty(SetCookie $cookie)
    {
        $cookieValue = $cookie->getValue();
        if ($cookieValue === null || $cookieValue === '') {
            $this->clear(
                $cookie->getDomain(),
                $cookie->getPath(),
                $cookie->getName()
            );
        }
    }
}
