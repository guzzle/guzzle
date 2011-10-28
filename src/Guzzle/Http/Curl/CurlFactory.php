<?php

namespace Guzzle\Http\Curl;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Guzzle;

/**
 * The default cURL factory to use with most HTTP requests
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CurlFactory implements CurlFactoryInterface
{
    /**
     * @var CurlFactory Singleton instance
     */
    private static $instance;

    /**
     * @var array Array of open curl handles {@see CurlHandle}
     */
    protected $handles = array();

    /**
     * Array of host as the key and the total number of allowed concurrent idle
     * connections per host.  Default is 2 per host.  This does not affect
     * concurrent connections per host.
     *
     * @var array
     */
    protected $maxIdlePerHost = array();

    /**
     * @var int Maximum number of seconds to leave an idle connection open
     */
    protected $maxIdleTime = -1;

    /**
     * Array of host as the key and maximum number of handles reuses
     * as the value per host.
     *
     * @var array
     */
    protected $maxConnectionReusesPerHost = array();

    /**
     * Singleton method to get a single instance of the default CurlFactory.
     *
     * Because the default curl factory will be most commonly used, it is
     * recommended to get the singleton instance of the CurlFactory when
     * creating standard curl handles.
     *
     * @return CurlFactory
     */
    public static function getInstance()
    {
        // @codeCoverageIgnoreStart
        if (!self::$instance) {
            self::$instance = new self();
        }
        // @codeCoverageIgnoreEnd

        return self::$instance;
    }

    /**
     * Get the number of connections per host
     *
     * @param bool $allocated (optional) Set to TRUE to get all allocated
     *      counts, false to get unallocated counts, or NULL to get all
     * @param string $host (optional) Only retrive the counts for a specific
     *      host (use both host and port)
     *
     * @return array
     */
    public function getConnectionsPerHost($allocated = null, $host = null)
    {
        $hostHandles = array();

        foreach ($this->handles as $i => $handle) {
            if ($allocated === true && !$handle->getOwner()) {
                continue;
            }
            if ($allocated === false && $handle->getOwner()) {
                continue;
            }
            $url = $handle->getUrl();
            $h = $url->getHost() . ':' . $url->getPort();
            if ($host && $h != $host) {
                continue;
            }
            if (!isset($hostHandles[$h])) {
                $hostHandles[$h] = 0;
            }
            $hostHandles[$h]++;
        }

        return $host ? (isset($hostHandles[$host]) ? $hostHandles[$host] : 0) : $hostHandles;
    }

    /**
     * Set the maximum amount of time to leave an idle connection open before
     * closing it.
     *
     * Currently allocated connections will not be closed when the
     * maximum amount of idle time has elapsed.  Use -1 (the default settings)
     * to leave connections open indefinitely until they are closed.
     *
     * @param int $maxSeconds Maximum number of seconds to allow a connection to
     *      remaing idle before closing it.
     *
     * @return CurlFactory
     */
    public function setMaxIdleTime($maxSeconds)
    {
        $this->maxIdleTime = (int) $maxSeconds;

        return $this;
    }

    /**
     * Set the maximum number of idle connections to allow on a specific host
     *
     * @param string $host Host to specify including port
     * @param int $total Total number of idle connections to keep open
     *
     * @return CurlFactory
     */
    public function setMaxIdleForHost($host, $total)
    {
        $this->maxIdlePerHost[$host] = $total;

        return $this;
    }

    /**
     * Set the maximum number of connection reuses for a specific host/port
     *
     * @param string $host Host to specify including port
     * @param int $max Max number of connection reuses per handle
     *
     * @return CurlFactory
     */
    public function setMaxConnectionReusesForHost($host, $max)
    {
        $this->maxConnectionReusesPerHost[$host] = $max;

        return $this;
    }

    /**
     * Get a cURL handle for an HTTP request
     *
     * @param RequestInterface $request Request object checking out the handle
     *
     * @return resource
     */
    public function getHandle(RequestInterface $request)
    {
        $retHandle = false;

        // Check to see if a handle can be reused
        foreach ($this->handles as $handle) {
            if (!$handle->getOwner() && $handle->isCompatible($request) && $handle->isAvailable()) {
                $retHandle = $handle;
                break;
            }
        }

        if (!$retHandle) {
            // If no matching handle was found, create a new handle
            $retHandle = $this->createHandle($request);
        } else {
            $curlOptions = $this->getOptions($request);
            // Cleanup a cURL handle that might have been used for a PUT
            // that used Transfer-Encoding: chunked
            $retHandle->setOption(CURLOPT_HTTPGET, true);
            $retHandle->setOptions($curlOptions);
            $request->getCurlOptions()->replace($curlOptions);
        }

        return $retHandle->checkout($request);
    }

    /**
     * Release a cURL handle back to the factory
     *
     * @param CurlHandle $handle Handle to release
     * @param bool $close (optional) Set to TRUE to close the handle
     *
     * @return CurlFactory
     */
    public function releaseHandle(CurlHandle $handle, $close = false)
    {
        $handle->unlock();
        if ($close && $handle->getHandle()) {
            curl_close($handle->getHandle());
        }

        // If the handle was closed then clean() will remove it.  It's also just
        // a good time to clean up the managed requests
        $this->clean();

        return $this;
    }

    /**
     * Release all cURL handles from the factory
     *
     * @param bool $close (optional) Set to TRUE to close open handles
     *
     * @return CurlFactory
     */
    public function releaseAllHandles($close = false)
    {
        foreach ($this->handles as $handle) {
            $handle->unlock();
            if ($close && $handle->getHandle()) {
                curl_close($handle->getHandle());
            }
        }

        $this->handles = array();

        return $this;
    }

    /**
     * Closes idle handles that exceed the default number of idle
     * connections per host, handles that are no longer connected, or
     * handles that have not been used for the max idle timespan.
     * Currently allocated handles are not subject to this method
     *
     * @param bool $purge (optional) Set to TRUE to remove all unallocated
     *      connections
     *
     * @return CurlFactory
     */
    public function clean($purge = false)
    {
        $hostHandles = array();

        foreach ($this->handles as $i => $handle) {

            // Skip allocated handles
            if ($handle->getOwner()) {
                continue;
            }

            if ($purge) {
                unset($this->handles[$i]);
                continue;
            }

            // Remove closed connections
            if (!$handle->isAvailable()) {
                unset($this->handles[$i]);
                continue;
            }

            // Remove connections that have been idle for too long
            if ($this->maxIdleTime > -1 && $handle->getIdleTime() >= $this->maxIdleTime) {
                unset($this->handles[$i]);
                continue;
            }

            $url = $handle->getUrl();
            if (!isset($hostHandles[$url->getHost()])) {
                $hostHandles[$url->getHost()] = array();
            }

            $hostHandles[$url->getHost() . ':' . $url->getPort()][] = $handle;
        }

        $this->handles = array_values($this->handles);

        // Prune too many handles per host
        if (!$purge) {
            foreach ($hostHandles as $host => $handles) {
                // Calculate how many idle connections to keep open for this host
                $max = isset($this->maxIdlePerHost[$host]) ? $this->maxIdlePerHost[$host] : 2;
                while (count($handles) > $max) {
                    $remove = array_shift($handles);
                    foreach ($this->handles as $i => $h) {
                        if ($h === $remove) {
                            unset($this->handles[$i]);
                            break;
                        }
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Create a cURL handle based on a request
     *
     * @param RequestInterface $request Request to create the handle for
     *
     * @return CurlHandle
     */
    protected function createHandle(RequestInterface $request)
    {
        $handle = curl_init();
        $options = $this->getOptions($request);
        // Set the CurlOptions on the request
        $request->getCurlOptions()->replace($options);
        // Apply the options to the cURL handle.
        curl_setopt_array($handle, $options);
        $h = new CurlHandle($handle, $options);
        $hostKey = $request->getHost() . ':' . $request->getPort();
        if (isset($this->maxConnectionReusesPerHost[$hostKey])) {
            $h->setMaxReuses($this->maxConnectionReusesPerHost[$hostKey]);
        }
        $this->handles[] = $h;

        return $h;
    }

    /**
     * Get all of the cURL options of a request
     *
     * @param RequestInterface $request Request to get options for
     *
     * @return array
     */
    protected function getOptions(RequestInterface $request)
    {
        $o = array();
        foreach ($this->getDefaultOptions($request) as $key => $value) {
            $o[$key] = $value;
        }
        foreach ($this->getSpecificOptions($request) as $key => $value) {
            $o[$key] = $value;
        }

        return $o;
    }

    /**
     * Set the basic defaults on a curl handle
     *
     * @param RequestInterface $request Request to generate handle defaults
     *
     * @return array Returns an array of default curl settings
     */
    protected function getDefaultOptions(RequestInterface $request)
    {
        // Array of default cURL options.
        $curlOptions = array(
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_CONNECTTIMEOUT => 120, // Connect timeout in seconds
            CURLOPT_RETURNTRANSFER => false, // Streaming the return, so no need
            CURLOPT_HEADER => false, // Retrieve the received headers
            CURLOPT_USERAGENT => $request->getHeader('User-Agent', Guzzle::getDefaultUserAgent()),
            CURLOPT_ENCODING => '', // Supports all encodings
            CURLOPT_PORT => $request->getPort(),
            CURLOPT_HTTP_VERSION => $request->getProtocolVersion(true),
            CURLOPT_PROXY => '', // Reset proxy settings,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_WRITEFUNCTION => function($curl, $data) use ($request) {
                $request->getEventManager()->notify('curl.callback.write', $data);

                return $request->getResponse()->getBody()->write($data);
            },
            CURLOPT_HEADERFUNCTION => function($curl, $data) use ($request) {
                return $request->receiveResponseHeader($data);
            },
            CURLOPT_READFUNCTION => function($ch, $fd, $length) use ($request) {
                $read = ($request->getBody()) ? $request->getBody()->read($length) : 0;
                if ($read) {
                    $request->getEventManager()->notify('curl.callback.read', $read, true);
                }

                return $read === false || $read === 0 ? '' : $read;
            },
            CURLOPT_PROGRESSFUNCTION => function($downloadSize, $downloaded, $uploadSize, $uploaded) use ($request) {
                $request->getEventManager()->notify('curl.callback.progress', array(
                    'download_size' => $downloadSize,
                    'downloaded' => $downloaded,
                    'upload_size' => $uploadSize,
                    'uploaded' => $uploaded
                ), true);
            }
        );

        // @codeCoverageIgnoreStart
        if (Guzzle::getCurlInfo('follow_location')) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = true;
            $curlOptions[CURLOPT_MAXREDIRS] = 5;
        }
        // @codeCoverageIgnoreEnd

        return $curlOptions;
    }

    /**
     * Get options specific to request and the request method
     *
     * @param RequestInterface $request Request to use
     *
     * @return array
     */
    protected function getSpecificOptions(RequestInterface $request)
    {
        // Specify settings according to the HTTP method.
        // The CURLOPT_CUSTOMREQUEST options are required to be able to reuse
        // a cURL handle that was once using a custom request method.
        switch ($request->getMethod()) {
            case 'GET':
                $curlOptions[CURLOPT_HTTPGET] = true;
                $curlOptions[CURLOPT_CUSTOMREQUEST] = 'GET';
                unset($curlOptions[CURLOPT_READFUNCTION]);
                break;
            case 'HEAD':
                $curlOptions[CURLOPT_NOBODY] = true;
                $curlOptions[CURLOPT_CUSTOMREQUEST] = 'HEAD';
                unset($curlOptions[CURLOPT_READFUNCTION]);
                break;
            case 'PUT':
                // cURL adds a content-type for PUT by default.
                if (!$request->hasHeader('Content-Type')) {
                    $request->setHeader('Content-Type', '');
                }
                // Uploading a file.  The size of the file must be specified
                // here, but the contents of the file will be streamed from
                // the CURLOPT_WRITEFUNCTION function.
                $curlOptions[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $curlOptions[CURLOPT_UPLOAD] = true;
                if ($request->getBody()) {
                    $size = $request->getBody()->getSize();
                    $curlOptions[CURLOPT_INFILESIZE] = null === $size ? -1 : $size;
                }
                break;
            case 'POST':
                $curlOptions[CURLOPT_CUSTOMREQUEST] = 'POST';
                $curlOptions[CURLOPT_POST] = true;
                $curlOptions[CURLOPT_INFILESIZE] = -1;
                if (!$request->getBody()) {
                    unset($curlOptions[CURLOPT_READFUNCTION]);
                }
                break;
            default:
                // Remove any other set options by making it perform as a GET
                $curlOptions[CURLOPT_HTTPGET] = true;
                $curlOptions[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
                break;
        }

        // Add any custom headers to the request
        $formattedHeaders = array();
        foreach ($request->getHeaders() as $key => $value) {
            // cURL will set the Content-Length header for PUT requests
            if ($key == 'Content-Length' && ($value === '' || $value === null || $request->getMethod() == 'PUT')) {
                continue;
            }
            $formattedHeaders[] = $key . ': ' . $value;
        }

        if (!empty($formattedHeaders)) {
            $curlOptions[CURLOPT_HTTPHEADER] = $formattedHeaders;
        }

        // Set custom cURL options
        foreach ($request->getCurlOptions() as $key => $value) {
            $curlOptions[$key] = $value;
        }

        return $curlOptions;
    }
}