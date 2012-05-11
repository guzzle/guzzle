<?php

namespace Guzzle\Http\Parser\Cookie;

/**
 * Default Guzzle implementation of a Cookie parser
 */
class CookieParser implements CookieParserInterface
{
    /**
     * {@inheritdoc}
     */
    public function parseCookie($cookie, $host = null, $path = null, $decode = true)
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
        static $parts = array(
            'domain'      => 'Domain',
            'path'        => 'Path',
            'max_age'     => 'Max-Age',
            'expires'     => 'Expires',
            'version'     => 'Version',
            'secure'      => 'Secure',
            'port'        => 'Port',
            'discard'     => 'Discard',
            'comment'     => 'Comment',
            'comment_url' => 'Comment-Url',
            'http_only'   => 'HttpOnly'
        );

        // Create the default return array
        $data = array_fill_keys(array_keys($parts), null);
        $data['cookies'] = array();
        $data['data'] = array();
        $data['path'] = '/';
        $data['http_only'] = false;

        // Add the default domain and path
        // These values are overridden if using Set-Cookie2 cookie values.
        if ($host) {
            $data['domain'] = $host;
        }

        if ($path) {
            $data['path'] = $path;
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
            if ($foundCookies) {
                foreach ($parts as $mapValue => $search) {
                    if (!strcasecmp($search, $key)) {
                        $key = $mapValue;
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
}
