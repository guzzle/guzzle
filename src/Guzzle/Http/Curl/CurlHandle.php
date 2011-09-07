<?php

namespace Guzzle\Http\Curl;

use Guzzle\Guzzle;
use Guzzle\Common\Stream\StreamHelper;
use Guzzle\Common\Collection;
use Guzzle\Http\Url;
use Guzzle\Http\Message\RequestInterface;

/**
 * Wrapper for a cURL handle
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CurlHandle
{
    /**
     * @var Collection Curl options
     */
    protected $options;

    /**
     * @var resouce Curl resource handle
     */
    protected $handle;

    /**
     * @var RequestInterface
     */
    protected $owner;

    /**
     * @var int Last time this handle was checked out
     */
    protected $lastUsedAt;

    /**
     * @var resource
     */
    protected $stderr;

    /**
     * @var int Number of times the handle has been (re)used
     */
    protected $useCount = 0;

    /**
     * @var int
     */
    protected $maxReuses;

    /**
     * @var array Statically cached array of cURL options that pollute handles
     */
    protected static $pollute;

    /**
     * Construct a new CurlHandle object that wraps a cURL handle
     *
     * @param resource $handle Configured cURL handle resource
     * @param Collection|array $options Curl options to use with the handle
     *
     * @throws InvalidArgumentException
     */
    public function __construct($handle, $options)
    {
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('Invalid handle provided');
        }

        $this->handle = $handle;

        if (is_array($options)) {
            $this->options = new Collection($options);
        } else if ($options instanceof Collection) {
            $this->options = clone $options;
        } else {
            throw new \InvalidArgumentException('Expected array or Collection');
        }

        $this->stderr = fopen('php://temp', 'r+');
        $this->setOption(CURLOPT_STDERR, $this->stderr);
        $this->setOption(CURLOPT_VERBOSE, true);

        $this->lastUsedAt = time();
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        if ($this->isAvailable()) {
            curl_close($this->handle);
        }
    }

    /**
     * Check if the handle is available and still OK
     *
     * @return bool
     */
    public function isAvailable()
    {
        //@codeCoverageIgnoreStart
        if (!$this->handle) {
            return false;
        }
        //@codeCoverageIgnoreEnd

        return false != @curl_getinfo($this->handle, CURLINFO_EFFECTIVE_URL);
    }

    /**
     * Check if the supplied cURL handle is wrapped by this object
     *
     * @param resource $handle Handle to check
     *
     * @return bool
     */
    public function isMyHandle($handle)
    {
        return $this->getHandle() === $handle;
    }

    /**
     * Get the last error that occurred on the cURL handle
     *
     * @return string
     */
    public function getError()
    {
        return $this->isAvailable() ? @curl_error($this->handle) : '';
    }

    /**
     * Get the last error number that occurred on the cURL handle
     *
     * @return int
     */
    public function getErrorNo()
    {
        return $this->isAvailable() ? @curl_errno($this->handle) : 0;
    }

    /**
     * Get cURL curl_getinfo data
     *
     * @param int $option (optional) Option to retrieve.  Pass null to retrieve
     *      retrieve all data as an array or pass a CURLINFO_* constant
     *
     * @return array|mixed
     */
    public function getInfo($option = null)
    {
        if (!$this->isAvailable()) {
            return null !== $option ? null : array();
        }

        return null !== $option
            ? @curl_getinfo($this->handle, $option)
            : @curl_getinfo($this->handle);
    }

    /**
     * Set the maximum number of times a handle can be reused
     *
     * @param int $max Maximum reuse count
     *
     * @return CurlHandle
     */
    public function setMaxReuses($max)
    {
        $this->maxReuses = $max;

        return $this;
    }

    /**
     * Get the number of times the handle has been used
     *
     * @return int
     */
    public function getUseCount()
    {
        return $this->useCount;
    }

    /**
     * Get the stderr output
     *
     * @param bool $asResource (optional) Set to TRUE to get an fopen resource
     *
     * @return string|resource
     */
    public function getStderr($asResource = false)
    {
        if (!$asResource) {
            fseek($this->stderr, 0);
            $e = stream_get_contents($this->stderr);
            fseek($this->stderr, 0, SEEK_END);

            return $e;
        }

        return $this->stderr;
    }

    /**
     * Get the owner of the curl handle
     *
     * @return RequestInterface|null
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * Set the request that owns this handle
     *
     * @param RequestInterface $reqeust Request that owns the handle
     *
     * @return CurlHandle
     */
    public function checkout(RequestInterface $request)
    {
        $this->owner = $request;
        $this->lastUsedAt = time();
        ftruncate($this->stderr, 0);
        fseek($this->stderr, 0);
        
        return $this;
    }

    /**
     * Unlock the handle from the request that checked it out
     *
     * If the unlocking request should be closed (received a Connection: close
     * or sent a Connection: close header), the curl connection will be closed.
     *
     * @return CurlHandle
     */
    public function unlock()
    {
        if ($this->isAvailable()) {
            $this->useCount++;
            if ((null !== $this->maxReuses && $this->useCount > $this->maxReuses) ||
                ($this->hasProblematicOption() || ($this->owner && ($this->owner->getHeader('Connection', null, true) == 'close' || ($this->owner->getResponse() && $this->owner->getResponse()->getHeader('Connection', null, true) == 'close'))))) {
                curl_close($this->handle);
                $this->handle = null;
                $this->useCount = 0;
            }
        }

        $this->owner = null;

        return $this;
    }

    /**
     * Get the URL that this handle is connecting to
     *
     * @return Url
     */
    public function getUrl()
    {
        return Url::factory($this->options->get(CURLOPT_URL));
    }

    /**
     * Get the amount of time that has elapsed since this handle was last used
     *
     * @return int
     */
    public function getIdleTime()
    {
        return time() - $this->lastUsedAt;
    }

    /**
     * Build the cURL handle or return the handle if it is already created
     *
     * @return handle|null Returns the cURL handle or null if it was closed
     */
    public function getHandle()
    {
        return $this->handle && is_resource($this->handle) ? $this->handle : null;
    }
    
    /**
     * Get a cURL option value
     * 
     * @param int|string $option Option to retrieve
     * 
     * @return mixed
     */
    public function getOption($option)
    {
        if (is_string($option)) {
            $option = constant($option);
        }

        return $this->options->get($option, null, true);
    }

    /**
     * Get all of the cURL options
     *
     * @param array $keys (optional) Specific keys to retrieve
     *
     * @return array
     */
    public function getOptions(array $keys = null)
    {
        return $this->options->getAll($keys);
    }

    /**
     * Check if this CurlHandle could be used to serve a request object
     *
     * @param RequestInterface $request Request to check
     *
     * @return bool Returns TRUE if it can FALSE if not
     */
    public function isCompatible(RequestInterface $request)
    {
        if ($this->owner === $request || ($this->owner && $this->owner->getCurlHandle() === $this)) {
            return true;
        }

        $url = $this->getUrl();

        return $request->getHost() == $url->getHost()
            && ($request->getPort() == $url->getPort() || $request->getPort() == $this->getOption(CURLOPT_PORT))
            && $request->getCurlOptions()->get(CURLOPT_PROXY) == $this->getOption(CURLOPT_PROXY);
    }

    /**
     * Set multiple options on the cURL handle
     *
     * @param array $options Options to set
     *
     * @return CurlHandle
     */
    public function setOptions(array $options)
    {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }

        return $this;
    }

    /**
     * Set a cURL options
     *
     * @param string|int $option Options to set
     * @param string|int|bool|null $value Value to set
     *
     * @return CurlHandle
     */
    public function setOption($option, $value)
    {
        if (is_string($option)) {
            $option = constant($option);
        }

        $this->options->set($option, $value);
        
        // if the handle is open, set the option on the handle
        if ($this->isAvailable()) {
            curl_setopt($this->handle, $option, $value);
        }

        return $this;
    }

    /**
     * Check if the handle has a problematic cURL option that would prevent it
     * from being reused arbitrarily
     *
     * @return bool
     */
    public function hasProblematicOption()
    {
        //@codeCoverageIgnoreStart
        if (!self::$pollute) {
            self::$pollute = array(
                CURLOPT_RANGE,
                CURLOPT_COOKIEFILE,
                CURLOPT_COOKIEJAR,
                CURLOPT_LOW_SPEED_LIMIT,
                CURLOPT_LOW_SPEED_TIME,
                CURLOPT_TIMEOUT,
                CURLOPT_FORBID_REUSE,
                CURLOPT_RESUME_FROM,
                CURLOPT_HTTPAUTH
            );

            // CURLOPT_TIMEOUT_MS was added in v7.16.2 (or 0x071602)
            if (defined('CURLOPT_TIMEOUT_MS')) {
                self::$pollute[] = constant('CURLOPT_TIMEOUT_MS');
            }
        }
        //@codeCoverageIgnoreEnd

        return count(array_intersect(self::$pollute, $this->options->getKeys())) > 0;
    }
}