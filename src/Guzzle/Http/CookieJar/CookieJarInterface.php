<?php

namespace Guzzle\Http\CookieJar;

/**
 * Interface for persisting cookies
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface CookieJarInterface
{
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
    function clear($domain = null, $path = null, $name = null);

    /**
     * Discard all temporary cookies.
     *
     * Scans for all cookies in the jar with either no expire field or a
     * true discard flag. To be called when the user agent shuts down according
     * to RFC 2965.
     *
     * @return int Returns the number of deleted cookies
     */
    function clearTemporary();

    /**
     * Delete any expired cookies
     *
     * @return int Returns the number of deleted cookies
     */
    function deleteExpired();

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
     *      cookie  (array)  - Array of cookie name (0) and value (1)
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
    function getCookies($domain = null, $path = null, $name = null, $skipDiscardable = false, $skipExpired = true);

    /**
     * Save a cookie
     *
     * @parm array $cookieData Cookie information, including the following:
     *      domain  (string, required) - Domain of the cookie
     *      path    (string, required) - Path of the cookie
     *      cookie                     - Array of cookie name (0) and value (1)
     *      max_age (int, required)    - Lifetime of the cookie in seconds
     *      version (int)              - Version of the cookie specification. Default is 0. RFC 2965 is 1
     *      secure  (bool)             - If it is a secure cookie
     *      discard (bool)             - If it is a discardable cookie
     *      comment (string)           - How the cookie is intended to be used
     *      comment_url (str)          - URL with info on how it will be used
     *      port (string)              - CSV list of ports
     *      http_only (bool)           - HTTP only cookie
     *
     * @return CookieJarInterface
     */
    function save(array $cookieData);
}