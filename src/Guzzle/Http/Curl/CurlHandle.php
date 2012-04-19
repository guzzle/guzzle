<?php

namespace Guzzle\Http\Curl;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Url;

/**
 * Immutable wrapper for a cURL handle
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
     * @var int CURLE_* error
     */
    protected $errorNo = CURLE_OK;

    /**
     * Factory method to create a new curl handle based on an HTTP request
     *
     * @param RequestInterface $request Request
     *
     * @return CurlHandle
     */
    public static function factory(RequestInterface $request)
    {
        $handle = curl_init();

        // Array of default cURL options.
        $curlOptions = array(
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_CONNECTTIMEOUT => 10, // Connect timeout in seconds
            CURLOPT_RETURNTRANSFER => false, // Streaming the return, so no need
            CURLOPT_HEADER => false, // Retrieve the received headers
            CURLOPT_USERAGENT => $request->getHeader('User-Agent', Guzzle::getDefaultUserAgent()),
            CURLOPT_ENCODING => '', // Supports all encodings
            CURLOPT_PORT => $request->getPort(),
            CURLOPT_HTTP_VERSION => $request->getProtocolVersion(true),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_STDERR => fopen('php://temp', 'r+'),
            CURLOPT_VERBOSE => true,
            CURLOPT_HTTPHEADER => array(),
            CURLOPT_HEADERFUNCTION => function($curl, $header) use ($request) {
                return $request->receiveResponseHeader($header);
            },
            CURLOPT_PROGRESSFUNCTION => function($downloadSize, $downloaded, $uploadSize, $uploaded) use ($request) {
                $request->dispatch('curl.callback.progress', array(
                    'request'       => $request,
                    'download_size' => $downloadSize,
                    'downloaded'    => $downloaded,
                    'upload_size'   => $uploadSize,
                    'uploaded'      => $uploaded
                ));
            }
        );

        // HEAD requests need no response body, everything else might
        if ($request->getMethod() != 'HEAD') {
            $curlOptions[CURLOPT_WRITEFUNCTION] = function($curl, $write) use ($request) {
                $request->dispatch('curl.callback.write', array(
                    'request' => $request,
                    'write'   => $write
                ));
                return $request->getResponse()->getBody()->write($write);
            };
        }

        // Account for PHP installations with safe_mode or open_basedir enabled
        // @codeCoverageIgnoreStart
        if (Guzzle::getCurlInfo('follow_location')) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = true;
            $curlOptions[CURLOPT_MAXREDIRS] = 5;
        }
        // @codeCoverageIgnoreEnd

        $headers = $request->getHeaders();

        // Specify settings according to the HTTP method
        switch ($request->getMethod()) {
            case 'GET':
                $curlOptions[CURLOPT_HTTPGET] = true;
                break;
            case 'HEAD':
                $curlOptions[CURLOPT_NOBODY] = true;
                unset($curlOptions[CURLOPT_WRITEFUNCTION]);
                break;
            case 'POST':
                $curlOptions[CURLOPT_POST] = true;
                break;
            case 'PUT':
            case 'PATCH':
                $curlOptions[CURLOPT_UPLOAD] = true;
                if ($request->hasHeader('Content-Length')) {
                    unset($headers['Content-Length']);
                    $curlOptions[CURLOPT_INFILESIZE] = $request->getHeader('Content-Length');
                }

                break;
        }

        if ($request instanceof EntityEnclosingRequestInterface) {

            // If no body is being sent, always send Content-Length of 0
            if (!$request->getBody() && !count($request->getPostFields())) {
                $headers['Content-Length'] = 0;
                unset($headers['Transfer-Encoding']);
                // Need to remove CURLOPT_UPLOAD to prevent chunked encoding
                unset($curlOptions[CURLOPT_UPLOAD]);
                unset($curlOptions[CURLOPT_POST]);
                // Not reading from a callback when using empty body
                unset($curlOptions[CURLOPT_READFUNCTION]);
            } else {
                // Add a callback for curl to read data to send with the request
                $curlOptions[CURLOPT_READFUNCTION] = function($ch, $fd, $length) use ($request) {
                    $read = '';
                    if ($request->getBody()) {
                        $read = $request->getBody()->read($length);
                        $request->dispatch('curl.callback.read', array(
                            'request' => $request,
                            'read'    => $read
                        ));
                    }
                    return !$read ? '' : $read;
                };
            }

            // If the Expect header is not present, prevent curl from adding it
            if (!$request->hasHeader('Expect')) {
                $curlOptions[CURLOPT_HTTPHEADER][] = 'Expect:';
            }
        }

        // Set custom cURL options
        foreach ($request->getCurlOptions() as $key => $value) {
            $curlOptions[$key] = $value;
        }

        // Add any custom headers to the request.  Empty headers will not be
        // added.  Headers explicitly set to NULL _will_ be added.
        foreach ($headers as $key => $value) {
            if ($value === null) {
                $curlOptions[CURLOPT_HTTPHEADER][] = "{$key}:";
            } else if ($key) {
                foreach ((array) $value as $val) {
                    $curlOptions[CURLOPT_HTTPHEADER][] = "{$key}: {$val}";
                }
            }
        }

        // Apply the options to the cURL handle.
        curl_setopt_array($handle, $curlOptions);
        $request->getParams()->set('curl.last_options', $curlOptions);

        return new static($handle, $curlOptions);
    }

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
        if (is_array($options)) {
            $this->options = new Collection($options);
        } else if ($options instanceof Collection) {
            $this->options = $options;
        } else {
            throw new \InvalidArgumentException('Expected array or Collection');
        }
        $this->handle = $handle;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Close the curl handle
     */
    public function close()
    {
        if (is_resource($this->handle)) {
            curl_close($this->handle);
        }
        $this->handle = null;
    }

    /**
     * Check if the handle is available and still OK
     *
     * @return bool
     */
    public function isAvailable()
    {
        return is_resource($this->handle) && false != curl_getinfo($this->handle, CURLINFO_EFFECTIVE_URL);
    }

    /**
     * Get the last error that occurred on the cURL handle
     *
     * @return string
     */
    public function getError()
    {
        return $this->isAvailable() ? curl_error($this->handle) : '';
    }

    /**
     * Get the last error number that occurred on the cURL handle
     *
     * @return int
     */
    public function getErrorNo()
    {
        if ($this->errorNo) {
            return $this->errorNo;
        }

        return $this->isAvailable() ? curl_errno($this->handle) : 0;
    }

    /**
     * Set the curl error number
     *
     * @param int $error Error number to set
     *
     * @return CurlHandle
     */
    public function setErrorNo($error)
    {
        $this->errorNo = $error;

        return $this;
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
        if (!is_resource($this->handle)) {
            return null;
        }

        if (null !== $option) {
            return curl_getinfo($this->handle, $option) ?: null;
        }

        return curl_getinfo($this->handle) ?: array();
    }

    /**
     * Get the stderr output
     *
     * @param bool $asResource (optional) Set to TRUE to get an fopen resource
     *
     * @return string|resource|null
     */
    public function getStderr($asResource = false)
    {
        $stderr = $this->getOptions()->get(CURLOPT_STDERR);
        if (!$stderr) {
            return null;
        }

        if ($asResource) {
            return $stderr;
        }

        fseek($stderr, 0);
        $e = stream_get_contents($stderr);
        fseek($stderr, 0, SEEK_END);

        return $e;
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
     * Get the wrapped curl handle
     *
     * @return handle|null Returns the cURL handle or null if it was closed
     */
    public function getHandle()
    {
        return $this->handle && $this->isAvailable() ? $this->handle : null;
    }

    /**
     * Get the cURL setopt options of the handle.  Changing values in the return
     * object will have no effect on the curl handle after it is created.
     *
     * @return Collection
     */
    public function getOptions()
    {
        return $this->options;
    }
}