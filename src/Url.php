<?php

namespace GuzzleHttp;

/**
 * Parses and generates URLs based on URL parts
 */
class Url
{
    private $scheme;
    private $host;
    private $port;
    private $username;
    private $password;
    private $path = '';
    private $fragment;
    private static $defaultPorts = ['http' => 80, 'https' => 443, 'ftp' => 21];

    /** @var Query Query part of the URL */
    private $query;

    /**
     * Factory method to create a new URL from a URL string
     *
     * @param string $url Full URL used to create a Url object
     *
     * @return Url
     * @throws \InvalidArgumentException
     */
    public static function fromString($url)
    {
        static $defaults = array('scheme' => null, 'host' => null,
            'path' => null, 'port' => null, 'query' => null,
            'user' => null, 'pass' => null, 'fragment' => null);

        if (false === ($parts = parse_url($url))) {
            throw new \InvalidArgumentException('Unable to parse malformed '
                . 'url: ' . $url);
        }

        $parts += $defaults;

        // Convert the query string into a Query object
        if ($parts['query'] || 0 !== strlen($parts['query'])) {
            $parts['query'] = Query::fromString($parts['query']);
        }

        return new static($parts['scheme'], $parts['host'], $parts['user'],
            $parts['pass'], $parts['port'], $parts['path'], $parts['query'],
            $parts['fragment']);
    }

    /**
     * Build a URL from parse_url parts. The generated URL will be a relative
     * URL if a scheme or host are not provided.
     *
     * @param array $parts Array of parse_url parts
     *
     * @return string
     */
    public static function buildUrl(array $parts)
    {
        $url = $scheme = '';

        if (!empty($parts['scheme'])) {
            $scheme = $parts['scheme'];
            $url .= $scheme . ':';
        }

        if (!empty($parts['host'])) {
            $url .= '//';
            if (isset($parts['user'])) {
                $url .= $parts['user'];
                if (isset($parts['pass'])) {
                    $url .= ':' . $parts['pass'];
                }
                $url .=  '@';
            }

            $url .= $parts['host'];

            // Only include the port if it is not the default port of the scheme
            if (isset($parts['port']) &&
                (!isset(self::$defaultPorts[$scheme]) ||
                 $parts['port'] != self::$defaultPorts[$scheme])
            ) {
                $url .= ':' . $parts['port'];
            }
        }

        // Add the path component if present
        if (isset($parts['path']) && strlen($parts['path'])) {
            // Always ensure that the path begins with '/' if set and something
            // is before the path
            if (isset($parts['host']) && $parts['path'][0] != '/') {
                $url .= '/';
            }
            $url .= $parts['path'];
        }

        // Add the query string if present
        if (isset($parts['query'])) {
            $queryStr = (string) $parts['query'];
            if ($queryStr || $queryStr === '0') {
                $url .= '?' . $queryStr;
            }
        }

        // Ensure that # is only added to the url if fragment contains anything.
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    /**
     * Create a new URL from URL parts
     *
     * @param string                   $scheme   Scheme of the URL
     * @param string                   $host     Host of the URL
     * @param string                   $username Username of the URL
     * @param string                   $password Password of the URL
     * @param int                      $port     Port of the URL
     * @param string                   $path     Path of the URL
     * @param Query|array|string $query    Query string of the URL
     * @param string                   $fragment Fragment of the URL
     */
    public function __construct(
        $scheme,
        $host,
        $username = null,
        $password = null,
        $port = null,
        $path = null,
        Query $query = null,
        $fragment = null
    ) {
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->fragment = $fragment;
        if (!$query) {
            $this->query = new Query();
        } else {
            $this->setQuery($query);
        }
        $this->setPath($path);
    }

    /**
     * Clone the URL
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /**
     * Returns the URL as a URL string
     *
     * @return string
     */
    public function __toString()
    {
        return static::buildUrl($this->getParts());
    }

    /**
     * Get the parts of the URL as an array
     *
     * @return array
     */
    public function getParts()
    {
        return array(
            'scheme'   => $this->scheme,
            'user'     => $this->username,
            'pass'     => $this->password,
            'host'     => $this->host,
            'port'     => $this->port,
            'path'     => $this->path,
            'query'    => $this->query,
            'fragment' => $this->fragment,
        );
    }

    /**
     * Set the host of the request.
     *
     * @param string $host Host to set (e.g. www.yahoo.com, yahoo.com)
     *
     * @return Url
     */
    public function setHost($host)
    {
        if (strpos($host, ':') === false) {
            $this->host = $host;
        } else {
            list($host, $port) = explode(':', $host);
            $this->host = $host;
            $this->setPort($port);
        }

        return $this;
    }

    /**
     * Get the host part of the URL
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Set the scheme part of the URL (http, https, ftp, etc.)
     *
     * @param string $scheme Scheme to set
     *
     * @return Url
     */
    public function setScheme($scheme)
    {
        // Remove the default port if one is specified
        if ($this->port && isset(self::$defaultPorts[$this->scheme]) &&
            self::$defaultPorts[$this->scheme] == $this->port
        ) {
            $this->port = null;
        }

        $this->scheme = $scheme;

        return $this;
    }

    /**
     * Get the scheme part of the URL
     *
     * @return string
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * Set the port part of the URL
     *
     * @param int $port Port to set
     *
     * @return Url
     */
    public function setPort($port)
    {
        $this->port = $port;

        return $this;
    }

    /**
     * Get the port part of the URl.
     *
     * If no port was set, this method will return the default port for the
     * scheme of the URI.
     *
     * @return int|null
     */
    public function getPort()
    {
        if ($this->port) {
            return $this->port;
        } elseif (isset(self::$defaultPorts[$this->scheme])) {
            return self::$defaultPorts[$this->scheme];
        }

        return null;
    }

    /**
     * Set the path part of the URL
     *
     * @param string $path Path string to set
     *
     * @return Url
     */
    public function setPath($path)
    {
        static $search  = [' ',   '?'];
        static $replace = ['%20', '%3F'];
        $this->path = str_replace($search, $replace, $path);

        return $this;
    }

    /**
     * Removes dot segments from a URL
     *
     * @return Url
     * @link http://tools.ietf.org/html/rfc3986#section-5.2.4
     */
    public function removeDotSegments()
    {
        static $noopPaths = ['' => true, '/' => true, '*' => true];
        static $ignoreSegments = ['' => true, '.' => true, '..' => true];

        if (isset($noopPaths[$this->path])) {
            return $this;
        }

        $results = [];
        $segments = $this->getPathSegments();
        foreach ($segments as $segment) {
            if ($segment == '..') {
                array_pop($results);
            } elseif (!isset($ignoreSegments[$segment])) {
                $results[] = $segment;
            }
        }

        // Combine the normalized parts and add the leading slash if needed
        if ($this->path[0] == '/') {
            $this->path = '/' . implode('/', $results);
        } else {
            $this->path = implode('/', $results);
        }

        // Add the trailing slash if necessary
        if ($this->path != '/' && isset($ignoreSegments[end($segments)])) {
            $this->path .= '/';
        }

        return $this;
    }

    /**
     * Add a relative path to the currently set path.
     *
     * @param string $relativePath Relative path to add
     *
     * @return Url
     */
    public function addPath($relativePath)
    {
        if ($relativePath != '/' &&
            is_string($relativePath) &&
            strlen($relativePath) > 0
        ) {
            // Add a leading slash if needed
            if ($relativePath[0] != '/') {
                $relativePath = '/' . $relativePath;
            }
            $this->setPath(str_replace('//', '/', $this->path . $relativePath));
        }

        return $this;
    }

    /**
     * Get the path part of the URL
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Get the path segments of the URL as an array
     *
     * @return array
     */
    public function getPathSegments()
    {
        return explode('/', $this->path);
    }

    /**
     * Set the password part of the URL
     *
     * @param string $password Password to set
     *
     * @return Url
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get the password part of the URL
     *
     * @return null|string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set the username part of the URL
     *
     * @param string $username Username to set
     *
     * @return Url
     */
    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Get the username part of the URl
     *
     * @return null|string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Get the query part of the URL as a Query object
     *
     * @return Query
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Set the query part of the URL
     *
     * @param Query|string|array $query Query string value to set. Can
     *     be a string that will be parsed into a Query object, an array
     *     of key value pairs, or a Query object.
     *
     * @return Url
     * @throws \InvalidArgumentException
     */
    public function setQuery($query)
    {
        if ($query instanceof Query) {
            $this->query = $query;
        } elseif (is_string($query)) {
            $this->query = Query::fromString($query);
        } elseif (is_array($query)) {
            $this->query = new Query($query);
        } else {
            throw new \InvalidArgumentException('Query must be a '
                . 'QueryInterface, array, or string');
        }

        return $this;
    }

    /**
     * Get the fragment part of the URL
     *
     * @return null|string
     */
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * Set the fragment part of the URL
     *
     * @param string $fragment Fragment to set
     *
     * @return Url
     */
    public function setFragment($fragment)
    {
        $this->fragment = $fragment;

        return $this;
    }

    /**
     * Check if this is an absolute URL
     *
     * @return bool
     */
    public function isAbsolute()
    {
        return $this->scheme && $this->host;
    }

    /**
     * Combine the URL with another URL and return a new URL instance.
     *
     * Follows the rules specific in RFC 3986 section 5.4.
     *
     * @param string $url Relative URL to combine with
     *
     * @return Url
     * @throws \InvalidArgumentException
     * @link http://tools.ietf.org/html/rfc3986#section-5.4
     */
    public function combine($url)
    {
        $url = static::fromString($url);

        // Use the more absolute URL as the base URL
        if (!$this->isAbsolute() && $url->isAbsolute()) {
            $url = $url->combine($this);
        }

        $parts = $url->getParts();

        // Passing a URL with a scheme overrides everything
        if ($parts['scheme']) {
            return new static(
                $parts['scheme'],
                $parts['host'],
                $parts['user'],
                $parts['pass'],
                $parts['port'],
                $parts['path'],
                clone $parts['query'],
                $parts['fragment']
            );
        }

        // Setting a host overrides the entire rest of the URL
        if ($parts['host']) {
            return new static(
                $this->scheme,
                $parts['host'],
                $parts['user'],
                $parts['pass'],
                $parts['port'],
                $parts['path'],
                clone $parts['query'],
                $parts['fragment']
            );
        }

        if (!$parts['path']) {
            // The relative URL has no path, so check if it is just a query
            $path = $this->path ?: '';
            $query = count($parts['query']) ? $parts['query'] : $this->query;
        } else {
            $query = $parts['query'];
            if ($parts['path'][0] == '/' || !$this->path) {
                // Overwrite the existing path if the rel path starts with "/"
                $path = $parts['path'];
            } else {
                // If the relative URL does not have a path or the base URL
                // path does not end in a "/" then overwrite the existing path
                // up to the last "/"
                $path = substr($this->path, 0, strrpos($this->path, '/') + 1) . $parts['path'];
            }
        }

        $result = new self(
            $this->scheme,
            $this->host,
            $this->username,
            $this->password,
            $this->port,
            $path,
            clone $query,
            $parts['fragment']
        );

        if ($path) {
            $result->removeDotSegments();
        }

        return $result;
    }
}
