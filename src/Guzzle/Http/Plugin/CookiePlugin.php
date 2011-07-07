<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event\Observer;
use Guzzle\Common\Event\Subject;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\CookieJar\CookieJarInterface;

/**
 * Adds, extracts, and persists cookies between HTTP requests
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CookiePlugin implements Observer
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
     * Parse a cookie string as set in a Set-Cookie HTTP header and return data
     * about the cookie
     *
     * @param string $cookie Cookie header value to parse
     * @param RequestInterface $request (optional) Used to get path and domain
     *      when not using Cookie-2
     * @param bool $decode (optional) Set to FALSE to not urldecode the values
     *
     * @return array|bool Returns FALSE on failure or returns an array of
     * arrays, with each of the sub arrays including:
     *      domain  (string) - Domain of the cookie
     *      path    (string) - Path of the cookie
     *      cookies (array)  - Associative array of cookie names and values
     *      max_age (int)    - Lifetime of the cookie in seconds
     *      version (int)    - Version of the cookie specification. RFC 2965 is 1
     *      secure  (bool)   - Whether or not this is a secure cookie
     *      discard (bool)   - Whether or not this is a discardable cookie
     *      custom (string)  - Custom cookie data array
     *      comment (string) - How the cookie is intended to be used
     *      comment_url (str)- URL that contains info on how it will be used
     *      port (array|str) - Array of ports or null
     *      http_only (bool) - HTTP only cookie
     */
    public static function parseCookie($cookie, RequestInterface $request = null, $decode = true)
    {
        if (!$cookie) {
            return false;
        }

        // Explode the cookie string using a series of semicolons
        $pieces = array_filter(array_map('trim', explode(';', $cookie)));

        // The name of the cookie (first kvp) must include an equal sign.
        if (!strpos($pieces[0], '=')) {
            return false;
        }

        // Map cookie values into snake_case values and use these regexp's to find data
        $parts = array(
            'domain' => '/^Domain$/i',
            'path' => '/^Path$/i',
            'max_age' => '/^Max(\-|_)Age$/i',
            'expires' => '/^Expires$/i',
            'version' => '/^Version$/i',
            'secure' => '/^Secure$/i',
            'port' => '/^Port$/i',
            'discard' => '/^Discard$/i',
            'comment' => '/^Comment$/i',
            'comment_url' => '/^Comment(\-|_)Url$/i',
            'http_only' => '/^HttpOnly$/i'
        );

        // Create the default return array
        $data = array_fill_keys(array_keys($parts), null);
        $data['cookies'] = array();
        $data['data'] = array();
        $data['path'] = '/';
        $data['http_only'] = false;

        // Add the default domain and path using the request reference
        // These values are overridden if using Set-Cookie2 cookie values.
        if ($request) {
            $data['domain'] = $request->getHost();
            $data['path'] = $request->getPath();
        }

        $foundCookies = $foundNonCookies = 0;

        // Add the cookie pieces into the parsed data array
        foreach ($pieces as $part) {

            $avPair = array_map('trim', explode('=', $part, 2));
            $key = $avPair[0];

            if (count($avPair) === 1) {
                // Matches secure, httpOnly, etc...
                $value = true;
            } else {
                $value = isset($avPair[1]) ? $avPair[1] : '';
                // Remove wrapping quotes
                if (strpos($value, '"') === 0) {
                    $value = substr($value, 1);
                }
                if (substr($value, -1, 1) == '"') {
                    $value = substr($value, 0, -1);
                }
            }

            // Check if the key is in the cookie pieces
            $found = false;
            $needle = strtolower($key);
            if ($foundCookies) {
                foreach ($parts as $mapValue => $regex) {
                    if (preg_match($regex, $needle)) {
                        $key = $needle = $mapValue;
                        $found = true;
                        break;
                    }
                }
            }

            // Decode the value if $decode is TRUE
            $value = ($decode && $value && !is_bool($value)) ? urldecode($value) : $value;

            if (0 == $foundCookies || !$found) {
                // If cookies have not yet been retrieved, or this value was 
                // not found in the cookie pieces array, treat as a cookie
                // IF non-cookies have been parsed, then this isn't a cookie,
                // but it's cookie data.  Cookies must be first, followed by data.
                if ($foundNonCookies == 0) {
                    $data['cookies'][] = $key . ($value ? ('=' . $value) : '');
                    $foundCookies++;
                } else {
                    $data['data'][$key] = $value;
                }
            } else {
                if ($key == 'port') {
                    $value = array_map('trim', explode(',', $value));
                }
                $data[$key] = $value;
                $foundNonCookies++;
            }
        }

        // Calculate the expires date
        if (!$data['expires'] && $data['max_age']) {
            $data['expires'] = time() + (int)$data['max_age'];
        }

        return $data;
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
        $cookie = $response->getSetCookie();
        $cookieData = array();
        
        if ($cookie) {
            foreach ((array) $cookie as $c) {
                $cdata = self::parseCookie($c, $response->getRequest());

                //@codeCoverageIgnoreStart
                if (!$cdata) {
                    continue;
                }
                //@codeCoverageIgnoreEnd
                
                $cookies = array();
                // Break up cookie v2 into multiple cookies
                if (count($cdata['cookies']) == 1) {
                    $cdata['cookie'] = explode('=', $cdata['cookies'][0], 2);
                    unset($cdata['cookies']);
                    $cookies = array($cdata);
                } else {
                    foreach ($cdata['cookies'] as $cookie) {
                        $row = $cdata;
                        unset($row['cookies']);
                        $row['cookie'] = explode('=', $cookie, 2);
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
     * {@inheritdoc}
     */
    public function update(Subject $subject, $event, $context = null)
    {
        // @codeCoverageIgnoreStart
        if (!($subject instanceof RequestInterface)) {
            return;
        }
        // @codeCoverageIgnoreEnd

        if ($event == 'request.before_send') {
            // The request is being prepared
            $this->addCookies($subject);
        } else if ($event == 'request.sent') {
            // The response is being processed
            $this->extractCookies($subject->getResponse());
        } else if ($event == 'request.receive.status_line') {
            if ($context['previous_response']) {
                $this->extractCookies($context['previous_response']);
            }
        }
    }
}