<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Plugin\Cookie\CookieJar;

use Guzzle\Common\Collection;
use Guzzle\Http\Plugin\PluginException;

/**
 * Persists cookies using a temporary array
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ArrayCookieJar implements CookieJarInterface
{
    /**
     * @var array CSV columns
     */
    protected $columns = array('comment', 'comment_url', 'cookies', 'data', 'discard', 'domain', 'expires', 'http_only', 'max_age', 'path', 'port', 'secure', 'version');

    /**
     * @var array Loaded cookie data
     */
    protected $cookies = array();

    /**
     * Clear cookies currently held in the Cookie jar.
     *
     * Invoking this method without arguments will empty the whole Cookie
     * jar.  If given a $domain argument only cookies belonging to that
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
    public function clear($domain = null, $path = null, $name = null)
    {
        $cookies = $this->getCookies($domain, $path, $name, false, false);

        $total = 0;

        foreach ($this->cookies as $i => $cookie) {
            foreach ($cookies as $c) {

                if ($c === $cookie) {

                    $total++;
                    unset($this->cookies[$i]);

                    // Removing only a single cookie value from a cookie?
                    if ($name) {

                        $c['cookies'] = array_values(array_filter($c['cookies'], function($v) use ($name) {
                            $parts = array_map('trim', explode('=', $v, 2));

                            return $parts[0] != trim($name);
                        }));

                        // Add the modified cookie back into the cookie jar
                        $this->save($c);
                    }

                }
            }
        }

        return $total;
    }

    /**
     * Discard all temporary cookies.
     *
     * Scans for all cookies in the jar with either no expire field or a
     * true discard flag. To be called when the user agent shuts down according
     * to RFC 2965.
     *
     * @return int Returns the number of deleted cookies
     */
    public function clearTemporary()
    {
        $ctime = time();

        return $this->prune(function($cookie) use ($ctime) {
            return (!$cookie['discard'] && $cookie['expires']);
        });
    }

    /**
     * Delete any expired cookies
     *
     * @return int Returns the number of deleted cookies
     */
    public function deleteExpired()
    {
        $ctime = time();

        return $this->prune(function($cookie) use ($ctime) {
            return (!$cookie['expires'] || $ctime < $cookie['expires']);
        });
    }

    /**
     * Get all of the matching cookies
     *
     * @param string $domain (optional) Domain of the cookie
     * @param string $path (optional) Path of the cookie
     * @param string $name (optional) Name of the cookie
     * @param bool $skipDiscardables (optional) Set to TRUE to skip cookies with
     *      the Discard attribute.
     * @param bool $skipExpired (optional) Set to FALSE to include expired
     *
     * @return array Returns an array of arrays.  Each array contains the
     *      following keys:
     *
     *      domain  (string) - Domain of the cookie
     *      path    (string) - Path of the cookie
     *      cookies (array)  - Array of cookie name value pairs (e.g. 'x=123')
     *      max_age (int)    - Lifetime of the cookie in seconds
     *      expires (int)    - The UNIX timestamp when the cookie expires
     *      version (int)    - Version of the cookie specification. RFC 2965 is 1
     *      secure  (bool)   - Whether or not this is a secure cookie
     *      discard (bool)   - Whether or not this is a discardable cookie
     *      comment (string) - How the cookie is intended to be used
     *      comment_url (str)- URL with info on how it will be used
     *      port (string)    - CSV list of ports
     *      http_only (bool) - HTTP only cookie
     */
    public function getCookies($domain = null, $path = null, $name = null, $skipDiscardable = false, $skipExpired = true)
    {
        $ctime = time();

        $ret = array_values(array_filter($this->cookies, function($cookie) use ($domain, $path, $name, $skipDiscardable, $skipExpired, $ctime) {

            // Make sure the cookie is not expired
            if ($skipExpired && $cookie['expires'] && $ctime > $cookie['expires']) {
                return false;
            }

            $domain = strtolower($domain);
            $cookie['domain'] = strtolower($cookie['domain']);

            // Normalize the domain value
            $domainMatch = false;
            if ($domain && $cookie['domain']) {
                if ($domain == $cookie['domain']) {
                    $domainMatch = true;
                } else if ($cookie['domain'][0] == '.') {
                    $domainMatch = preg_match('/' . preg_quote($cookie['domain']) . '$/', $domain);
                }
            }

            // Check cookie name matches
            $nameMatch = false;
            if ($name) {
                foreach ($cookie['cookies'] as $c) {
                    $parts = explode('=', $c, 2);
                    if ($parts[0] == $name) {
                        $nameMatch = true;
                        break;
                    }
                }
            }

            if (!$domain || $domainMatch) {
                if (!$path || !strcasecmp($path, $cookie['path']) || 0 === stripos($path, $cookie['path'])) {
                    if (!$name || $nameMatch) {
                        if (!$skipDiscardable || !$cookie['discard']) {
                            return true;
                        }
                    }
                }
            }

            return false;
        }));

        return $ret;
    }

    /**
     * Save a cookie
     *
     * @parm array $cookieData Cookie information, including the following:
     *      domain  (string, required) - Domain of the cookie
     *      path    (string, required) - Path of the cookie
     *      cookies                    - Array of cookie names and values as string
     *      max_age (int, required)    - Lifetime of the cookie in seconds
     *      version (int)              - Version of the cookie specification. Default is 0. RFC 2965 is 1
     *      secure  (bool)             - If it is a secure cookie
     *      discard (bool)             - If it is a discardable cookie
     *      comment (string)           - How the cookie is intended to be used
     *      comment_url (str)          - URL with info on how it will be used
     *      port (string)              - CSV list of ports
     *      http_only (bool)           - HTTP only cookie
     *
     * @return ArrayCookieJar
     */
    public function save(array $cookieData)
    {
        if (!isset($cookieData['domain'])) {
            throw new \InvalidArgumentException('Cookies require a domain');
        }

        if (!isset($cookieData['cookies'])) {
            throw new \InvalidArgumentException('Cookies require a names and values');
        }

        // Extract the expires value and turn it into a UNIX timestamp if needed
        $cookieData['expires'] = isset($cookieData['expires']) ? (is_numeric($cookieData['expires']) ? $cookieData['expires'] : strtotime($cookieData['expires'])) : null;
        $cookieData['path'] = isset($cookieData['path']) ? $cookieData['path'] : '/';
        $cookieData['max_age'] = isset($cookieData['max_age']) ? $cookieData['max_age'] : 0;
        $cookieData['comment'] = isset($cookieData['comment']) ? $cookieData['comment'] : null;
        $cookieData['comment_url'] = isset($cookieData['comment_url']) ? $cookieData['comment_url'] : null;
        $cookieData['port'] = isset($cookieData['port']) ? $cookieData['port'] : array();
        $cookieData['version'] = isset($cookieData['version']) ? $cookieData['version'] : null;
        $cookieData['secure'] = array_key_exists('secure', $cookieData) ? $cookieData['secure'] : null;
        $cookieData['discard'] = array_key_exists('discard', $cookieData) ? $cookieData['discard'] : null;
        $cookieData['http_only'] = array_key_exists('http_only', $cookieData) ? $cookieData['http_only'] : false;

        // Calculate the expires date
        if (!$cookieData['expires'] && $cookieData['max_age']) {
            $cookieData['expires'] = time() + (int) $cookieData['max_age'];
        }

        $alreadyPresent = false;
        foreach ($this->cookies as $cookie) {
            foreach ($cookie as $k => $v) {
                if ($cookieData[$k] != $v) {
                    continue 2;
                }
            }

            $alreadyPresent = true;
            break;
        }

        if (!$alreadyPresent) {
            $this->cookies[] = $cookieData;
        }

        return $this;
    }

    /**
     * Prune the cookies using a callback function
     *
     * @param Closure $callback Callback function
     *
     * @return int Returns the number of removed cookies
     */
    protected function prune(\Closure $callback)
    {
        $originalCount = count($this->cookies);

        $this->cookies = array_filter($this->cookies, $callback);

        return $originalCount - count($this->cookies);
    }
}