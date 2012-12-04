<?php

namespace Guzzle\Http\Message;

use DateTime;
use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Abstract HTTP request/response message
 */
abstract class AbstractMessage implements MessageInterface
{
    /**
     * @var array HTTP headers
     */
    protected $headers = array();

    /**
     * @var Collection Custom message parameters that are extendable by plugins
     */
    protected $params;

    /**
     * @var array Cache-Control directive information
     */
    private $cacheControl = array();

    /**
     * @var Warning[] Warning headers
     */
    private $warnings = array();

    /*
     * @var string HTTP protocol version of the message
     */
    protected $protocolVersion = '1.1';

    /**
     * {@inheritdoc}
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * {@inheritdoc}
     */
    public function addHeader($header, $value)
    {
        $key = strtolower($header);
        if (!isset($this->headers[$key])) {
            $this->headers[$key] = new Header($header, $value);
        } else {
            $this->headers[$key]->add($value, $header);
        }
        $this->changedHeader($key);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addHeaders(array $headers)
    {
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader($header, $string = false)
    {
        $key = strtolower($header);
        if (!isset($this->headers[$key])) {
            return null;
        }

        return $string ? (string) $this->headers[$key] : $this->headers[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders($asObjects = false)
    {
        if ($asObjects) {
            $result = $this->headers;
        } else {
            $result = array();
            // Convert all of the headers into a collection
            foreach ($this->headers as $header) {
                foreach ($header->raw() as $key => $value) {
                    $result[$key] = $value;
                }
            }
        }

        return new Collection($result);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderLines()
    {
        $headers = array();
        foreach ($this->headers as $value) {
            $glue = $value->getGlue();
            foreach ($value->raw() as $key => $v) {
                $headers[] = rtrim($key . ': ' . implode($glue, $v));
            }
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function setHeader($header, $value)
    {
        // Remove any existing header
        $key = strtolower($header);
        unset($this->headers[$key]);

        if ($value instanceof Header) {
            $this->headers[$key] = $value;
        } else {
            // Allow for 0, '', and NULL to be set
            if (!$value) {
                $value = array($value);
            }
            $this->headers[$key] = new Header($header, $value);
        }
        $this->changedHeader($key);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setHeaders(array $headers)
    {
        // Get the keys that are changing
        $changed = array_keys($this->headers);
        // Erase the old headers
        $this->headers = array();
        // Add the new headers
        foreach ($headers as $key => $value) {
            $changed[] = $key;
            $this->addHeader($key, $value);
        }

        // Notify of the changed headers
        foreach (array_unique($changed) as $header) {
            $this->changedHeader(strtolower($header));
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasHeader($header)
    {
        return array_key_exists(strtolower($header), $this->headers);
    }

    /**
     * {@inheritdoc}
     */
    public function removeHeader($header)
    {
        $header = strtolower($header);
        unset($this->headers[$header]);
        $this->changedHeader($header);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTokenizedHeader($header, $token = ';')
    {
        if (!$this->hasHeader($header)) {
            return null;
        }

        $data = new Collection();

        foreach ($this->getHeader($header) as $singleValue) {
            preg_match_all('#,?(?!$)(?<match>([^' . preg_quote($token) . '"]?("[^"]*")?)+)#', $singleValue, $matches);
            foreach ($matches['match'] as $kvp) {
                if('' == $kvp ) {
                    continue;
                }
                $parts = explode('=', $kvp, 2);
                if (!isset($parts[1])) {
                    $data[count($data)] = trim($parts[0]);
                } else {
                    $data->add(trim($parts[0]), trim($parts[1]));
                }
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data->set($key, array_unique($value));
            }
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function setTokenizedHeader($header, $data, $token = ';')
    {
        if (!($data instanceof Collection) && !is_array($data)) {
            throw new InvalidArgumentException('Data must be a Collection or array');
        }

        $values = array();
        foreach ($data as $key => $value) {
            foreach ((array) $value as $v) {
                $values[] = is_int($key) ? $v : $key . '=' . $v;
            }
        }

        return $this->setHeader($header, implode($token, $values));
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheControlDirective($directive)
    {
        return isset($this->cacheControl[$directive]) ? $this->cacheControl[$directive] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasCacheControlDirective($directive)
    {
        return isset($this->cacheControl[$directive]);
    }

    /**
     * {@inheritdoc}
     */
    public function addCacheControlDirective($directive, $value = true)
    {
        $this->cacheControl[$directive] = $value;
        $this->rebuildCacheControlDirective();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeCacheControlDirective($directive)
    {
        if (array_key_exists($directive, $this->cacheControl)) {
            unset($this->cacheControl[$directive]);
            $this->rebuildCacheControlDirective();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getWarning($code, $agent = null) {
        foreach($this->warnings as $warning) {
            if(null !== $agent) {
                if($code == $warning->getCode() && $agent === $warning->getAgent()) {
                    return $warning;
                }
            } elseif($code == $warning->getCode()) {
                return $warning;
            }
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getWarnings($code = null, $agent = null) {
        if(null === $code && null === $agent) {
            return $this->warnings;
        }

        $warnings = array();
        foreach($this->warnings as $warning) {
            if((null === $code || $code == $warning->getCode()) && (null === $agent || $agent == $warning->getAgent())) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * {@inheritdoc}
     */
    public function hasWarning($code, $agent = null) {
        return null !== $this->getWarning($code, $agent);
    }

    /**
     * {@inheritdoc}
     */
    public function hasWarnings($code = null, $agent = null) {
        return count($this->getWarnings($code, $agent)) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function addWarning($code, $agent, $text = null, DateTime $date = null) {
        if(false === $this->hasWarning($code, $agent)) {
            $this->warnings[] = new Warning($code, $agent, $text, $date);
            $this->rebuildWarningHeader();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeWarning($code, $agent = null) {
        foreach($this->warnings as $key => $warning) {
            if(null !== $agent) {
                if($code == $warning->getCode() && $agent === $warning->getAgent()) {
                    unset($this->warnings[$key]);
                    break;
                }
            } elseif($code == $warning->getCode()) {
                unset($this->warnings[$key]);
                break;
            }
        }
        $this->rebuildWarningHeader();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeWarnings($code = null, $agent = null) {
        foreach($this->warnings as $key => $warning) {
            if((null === $code || $code == $warning->getCode()) && (null === $agent || $agent == $warning->getAgent())) {
                unset($this->warnings[$key]);
            }
        }
        $this->rebuildWarningHeader();

        return $this;
    }

    /**
     * Check to see if the modified headers need to reset any of the managed
     * headers like cache-control
     *
     * @param string $header Header that changed
     */
    protected function changedHeader($header)
    {
        switch($header) {
            case 'cache-control':
                $this->parseCacheControlDirective();
                break;
            case 'warning':
                $this->parseWarningHeader();
                break;
        }
    }

    /**
     * Parse the Cache-Control HTTP header into an array
     */
    private function parseCacheControlDirective()
    {
        $this->cacheControl = array();
        $tokenized = $this->getTokenizedHeader('Cache-Control', ',') ?: array();
        foreach ($tokenized as $key => $value) {
            if (is_numeric($key)) {
                $this->cacheControl[$value] = true;
            } else {
                $this->cacheControl[$key] = $value;
            }
        }
    }

    /**
     * Rebuild the Cache-Control HTTP header using the user-specified values
     */
    private function rebuildCacheControlDirective()
    {
        $cacheControl = array();
        foreach ($this->cacheControl as $key => $value) {
            $cacheControl[] = ($value === true) ? $key : ($key . '=' . $value);
        }
        $this->headers['cache-control'] = new Header('Cache-Control', $cacheControl, ', ');
    }

    /**
     * Parse the Warning HTTP header into an array
     */
    private function parseWarningHeader()
    {
        $this->warnings = array();
        $tokenized = $this->getTokenizedHeader('Warning', ',') ?: array();
        foreach ($tokenized as $value) {
            $this->warnings[] = Warning::fromHeader($value);
        }
    }

    /**
     * Rebuild the Warning HTTP header using the user-specified values
     */
    private function rebuildWarningHeader()
    {
        $warnings = array();
        foreach ($this->warnings as $warning) {
            $warnings[] = (string)$warning;
        }
        $this->headers['warning'] = new Header('Warning', $warnings, ', ');
    }
}
