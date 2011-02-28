<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http;

use Guzzle\Common\Subject\AbstractSubject;

/**
 * Parses and generates URLs based on URL parts.  In favor of performance,
 * URL parts are not validated.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Url extends AbstractSubject
{
    /**#@+
     * @var string URL parts
     */
    protected $scheme;
    protected $host;
    protected $port;
    protected $username;
    protected $password;
    protected $path;
    protected $fragment;
    /**#@+*/

    /**
     * @var QueryString Query part of the URL
     */
    protected $query;

    /**
     * Factory method to create a new URL from a URL string
     *
     * @param string $url Full URL used to create a Url object
     *
     * @return Url
     * @throws HttpException when an invalid URL is encountered
     */
    public static function factory($url)
    {
        // Parse the URL into parts
        $parts = array_merge(array(
            'path' => null,
            'port' => null,
            'query' => null,
            'user' => null,
            'pass' => null,
            'fragment' => null
        ), parse_url($url));

        if (!isset($parts['host']) || !isset($parts['scheme'])) {
            throw new HttpException(
                'Invalid URL sent to ' . __METHOD__ . ': ' . $url
            );
        }

        if ($parts['query']) {
            $query = array();
            parse_str($parts['query'], $query);
            $parts['query'] = $query ? new QueryString($query) : null;
        }

        return new self($parts['scheme'], $parts['host'], $parts['user'],
            $parts['pass'], $parts['port'], $parts['path'], $parts['query'],
            $parts['fragment']);
    }

    /**
     * Buld a URL from parse_url parts.  The generated URL will be a relative
     * URL if a scheme or host are not provided.
     *
     * @param array $parts Array of parse_url parts
     *
     * @return string
     */
    public static function buildUrl(array $parts)
    {
        $url = $scheme = '';

        if (isset($parts['scheme'])) {
            $scheme = $parts['scheme'];
            $url .= $scheme . '://';
        }

        if (isset($parts['host'])) {

            if (isset($parts['user'])) {
                $url .= $parts['user'];
                if (isset($parts['pass'])) {
                    $url .= ':' . $parts['pass'];
                }
                $url .=  '@';
            }

            $url .= $parts['host'];

            // Only include the port if it is not the default port of the scheme
            if (isset($parts['port'])
                && !(($scheme == 'http' && (int) $parts['port'] == 80)
                    || ($scheme == 'https' && (int) $parts['port'] == 443))) {
                $url .= ':' . $parts['port'];
            }
        }

        if (empty($parts['path'])) {
            $url .= '/';
        } else {
            if ($parts['path'][0] != '/') {
                $parts['path'] = '/' . $parts['path'];
            }

            $url .= $parts['path'];
        }

        // Add the query string if present
        if (!empty($parts['query'])) {
            if ($parts['query'][0] != '?') {
                $url .= array_key_exists('query_prefix', $parts)
                    ? $parts['query_prefix'] : '?';
            }
            $url .= $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    /**
     * Create a new URL from URL parts
     *
     * @param string $scheme Scheme of the URL
     * @param string $host Host of the URL
     * @param string $username (optional) Username of the URL
     * @param string $password (optional) Password of the URL
     * @param int $port (optional) Port of the URL
     * @param string $path (optional) Path of the URL
     * @param QueryString $query (optional) Query string of the URL
     * @param string $fragment (optional) Fragment of the URL
     *
     * @throws HttpException
     */
    public function __construct($scheme, $host, $username = null, $password = null, $port = null, $path = null, QueryString $query = null, $fragment = null)
    {
        $this->setScheme($scheme);
        $this->setHost($host);
        if ($username) {
            $this->setUsername($username);
            if ($password) {
                $this->setPassword($password);
            }
        }

        if ($port) {
            $this->setPort($port);
        }

        if ($path) {
            $this->setPath($path);
        }

        $this->setQuery($query ?: new QueryString());
        
        if ($fragment) {
            $this->setFragment($fragment);
        }
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
        return self::buildUrl($this->getParts());
    }

    /**
     * Get the parts of the URL as an array
     *
     * @return array
     */
    public function getParts()
    {
        return array(
            'scheme' => $this->scheme,
            'user' => $this->username,
            'pass' => $this->password,
            'host' => $this->host,
            'port' => $this->port,
            'path' => $this->getPath(),
            'query' => (string)$this->query,
            'fragment' => $this->fragment,
            'query_prefix' => $this->query->getPrefix()
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
        $this->host = $host;
        $this->getSubjectMediator()->notify('change_host', $this->host, true);

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
     * Set the scheme part of the URL (http, https, ftp, etc)
     *
     * @param string $scheme Scheme to set
     *
     * @return Url
     */
    public function setScheme($scheme)
    {
        $this->scheme = $scheme;
        $this->getSubjectMediator()->notify('change_scheme', $this->scheme, true);

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
        $this->getSubjectMediator()->notify('change_port', $this->port, true);

        return $this;
    }

    /**
     * Get the port part of the URl.  Will return the default port for a given
     * scheme if a port has not explicitly been set.
     *
     * @return int|null
     */
    public function getPort()
    {
        if ($this->port) {
            return $this->port;
        } else if ($this->scheme == 'http') {
            return 80;
        } else if ($this->scheme == 'https') {
            return 443;
        } else {
            return null;
        }
    }

    /**
     * Set the path part of the URL
     *
     * @param array|string $path Path to set (a path string or array of path
     *      segments)
     *
     * @return Url
     */
    public function setPath($path)
    {
        $this->path = is_array($path) ? implode('/', $path) : $path;
        if ($this->path != '*' && substr($this->path, 0, 1) != '/') {
            $this->path = '/' . $this->path;
        }
        $this->getSubjectMediator()->notify('change_path', $this->getPath(), true);

        return $this;
    }

    /**
     * Get the path part of the URL
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path ?: '/';
    }

    /**
     * Get the path segments of the URL as an array
     *
     * @return array
     */
    public function getPathSegments()
    {
        return array_slice(explode('/', $this->path), 1);
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
        $this->getSubjectMediator()->notify('change_password', $this->password, true);

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
        $this->getSubjectMediator()->notify('change_username', $this->username, true);

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
     * Get the query part of the URL as a QueryString object
     *
     * @return QueryString
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Set the query part of the URL
     *
     * @param QueryString|string $query Query to set
     *
     * @return Url
     */
    public function setQuery($query)
    {
        $this->query = $query;
        $this->getSubjectMediator()->notify('change_query', $this->query, true);

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
        $this->getSubjectMediator()->notify('change_fragment', $this->fragment);

        return $this;
    }
}