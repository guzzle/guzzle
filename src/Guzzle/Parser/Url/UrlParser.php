<?php

namespace Guzzle\Parser\Url;

/**
 * Parses URLs into parts using PHP's built-in parse_url() function
 */
class UrlParser implements UrlParserInterface
{
    /**
     * @var bool Whether or not to work with UTF-8 strings
     */
    protected $utf8 = false;

    /**
     * Set whether or not to attempt to handle UTF-8 strings (still WIP)
     *
     * @param bool $handleUtf Set to TRUE to handle UTF string
     */
    public function setUtf8Support($utf8)
    {
        $this->utf8 = $utf8;
    }

    /**
     * {@inheritdoc}
     */
    public function parseUrl($url)
    {
        $parts = parse_url($url);

        // Need to handle query parsing specially for UTF-8 requirements
        if ($this->utf8 && isset($parts['query'])) {
            $queryPos = strpos($url, '?');
            if (isset($parts['fragment'])) {
                $parts['query'] = substr($url, $queryPos + 1, strpos($url, '#') - $queryPos - 1);
            } else {
                $parts['query'] = substr($url, $queryPos + 1);
            }
        }

        $parts['scheme'] = isset($parts['scheme']) ? $parts['scheme'] : null;
        $parts['host'] = isset($parts['host']) ? $parts['host'] : null;
        $parts['path'] = isset($parts['path']) ? $parts['path'] : null;
        $parts['port'] = isset($parts['port']) ? $parts['port'] : null;
        $parts['query'] = isset($parts['query']) ? $parts['query'] : null;
        $parts['user'] = isset($parts['user']) ? $parts['user'] : null;
        $parts['pass'] = isset($parts['pass']) ? $parts['pass'] : null;
        $parts['fragment'] = isset($parts['fragment']) ? $parts['fragment'] : null;

        return $parts;
    }
}
