<?php

namespace Guzzle\Http\Subscriber\CookieJar;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * Cookie cookieJar that stores cookies an an array
 */
class ArrayCookieJar implements CookieJarInterface, \Serializable
{
    /** @var array Loaded cookie data */
    protected $cookies = array();

    /** @var bool Whether or not strict mode is enabled. When enabled, exceptions will be thrown for invalid cookies */
    protected $strictMode;

    /**
     * @param bool $strictMode Set to true to throw exceptions when invalid cookies are added to the cookie jar
     */
    public function __construct($strictMode = false)
    {
        $this->strictMode = $strictMode;
    }

    /**
     * Create a new Cookie jar from an associative array and domain
     *
     * @param array  $cookies Cookies to create the jar from
     * @param string $domain  Domain to set the cookies to
     *
     * @return self
     */
    public static function fromArray(array $cookies, $domain)
    {
        $cookieJar = new ArrayCookieJar();
        foreach ($cookies as $name => $value) {
            $cookieJar->add(new SetCookie([
                'Domain'  => $domain,
                'Name'    => $name,
                'Value'   => $value,
                'Discard' => true
            ]));
        }

        return $cookieJar;
    }

    /**
     * Enable or disable strict mode on the cookie jar
     *
     * @param bool $strictMode Set to true to throw exceptions when invalid cookies are added. False to ignore them.
     *
     * @return self
     */
    public function setStrictMode($strictMode)
    {
        $this->strictMode = $strictMode;
    }

    public function remove($domain = null, $path = null, $name = null)
    {
        $cookies = $this->all($domain, $path, $name, false, false);
        $this->cookies = array_filter($this->cookies, function (SetCookie $cookie) use ($cookies) {
            return !in_array($cookie, $cookies, true);
        });

        return $this;
    }

    public function removeTemporary()
    {
        $this->cookies = array_filter($this->cookies, function (SetCookie $cookie) {
            return !$cookie->getDiscard() && $cookie->getExpires();
        });

        return $this;
    }

    public function removeExpired()
    {
        $currentTime = time();
        $this->cookies = array_filter($this->cookies, function (SetCookie $cookie) use ($currentTime) {
            return !$cookie->getExpires() || $currentTime < $cookie->getExpires();
        });

        return $this;
    }

    public function all($domain = null, $path = null, $name = null, $skipDiscardable = false, $skipExpired = true)
    {
        return array_values(array_filter($this->cookies, function (SetCookie $cookie) use (
            $domain,
            $path,
            $name,
            $skipDiscardable,
            $skipExpired
        ) {
            return false === (($name && $cookie->getName() != $name) ||
                ($skipExpired && $cookie->isExpired()) ||
                ($skipDiscardable && ($cookie->getDiscard() || !$cookie->getExpires())) ||
                ($path && !$cookie->matchesPath($path)) ||
                ($domain && !$cookie->matchesDomain($domain)));
        }));
    }

    public function add(SetCookie $cookie)
    {
        // Only allow cookies with set and valid domain, name, value
        $result = $cookie->validate();
        if ($result !== true) {
            if ($this->strictMode) {
                throw new \RuntimeException('Invalid cookie: ' . $result);
            } else {
                return false;
            }
        }

        // Resolve conflicts with previously set cookies
        foreach ($this->cookies as $i => $c) {

            // Two cookies are identical, when their path, and domain are identical
            if ($c->getPath() != $cookie->getPath() ||
                $c->getDomain() != $cookie->getDomain() ||
                $c->getName() != $cookie->getName()
            ) {
                continue;
            }

            // The previously set cookie is a discard cookie and this one is not so allow the new cookie to be set
            if (!$cookie->getDiscard() && $c->getDiscard()) {
                unset($this->cookies[$i]);
                continue;
            }

            // If the new cookie's expiration is further into the future, then replace the old cookie
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

    /**
     * Serializes the cookie cookieJar
     *
     * @return string
     */
    public function serialize()
    {
        // Only serialize long term cookies and unexpired cookies
        return json_encode(array_map(function (SetCookie $cookie) {
            return $cookie->toArray();
        }, $this->all(null, null, null, true, true)));
    }

    /**
     * Unserializes the cookie cookieJar
     */
    public function unserialize($data)
    {
        $data = json_decode($data, true);
        if (empty($data)) {
            $this->cookies = array();
        } else {
            $this->cookies = array_map(function (array $cookie) {
                return new SetCookie($cookie);
            }, $data);
        }
    }

    /**
     * Returns the total number of stored cookies
     *
     * @return int
     */
    public function count()
    {
        return count($this->cookies);
    }

    /**
     * Returns an iterator
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->cookies);
    }

    public function addCookiesFromResponse(RequestInterface $request, ResponseInterface $response)
    {
        if ($cookieHeader = $response->getHeader('Set-Cookie')) {
            foreach ($cookieHeader as $cookie) {
                $sc = SetCookie::fromString($cookie);
                if (!$sc->getDomain()) {
                    $sc->setDomain($request->getHost());
                }
                $this->add($sc);
            }
        }
    }

    public function getMatchingCookies(RequestInterface $request)
    {
        // Find cookies that match this request
        $cookies = $this->all($request->getHost(), $request->getPath());
        // Remove ineligible cookies
        foreach ($cookies as $index => $cookie) {
            if ($cookie->getSecure() && $request->getScheme() != 'https') {
                unset($cookies[$index]);
            }
        };

        return $cookies;
    }
}
