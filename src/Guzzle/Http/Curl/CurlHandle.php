<?php

namespace Guzzle\Http\Curl;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Exception\RuntimeException;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Parser\ParserRegistry;
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
     * @var resource Curl resource handle
     */
    protected $handle;

    /**
     * @var int CURLE_* error
     */
    protected $errorNo = CURLE_OK;

    /**
     * Factory method to create a new curl handle based on an HTTP request.
     *
     * There are some helpful options you can set to enable specific behavior:
     * - debug:    Set to true to enable cURL debug functionality to track the
     *             actual headers sent over the wire.  The
     * - progress: Set to true to enable progress function callbacks. Most
     *             users do not need this, so it has been disabled by default.
     *
     * @param RequestInterface $request Request
     *
     * @return CurlHandle
     */
    public static function factory(RequestInterface $request)
    {
        $mediator = new RequestMediator($request);
        $requestCurlOptions = $request->getCurlOptions();
        $tempContentLength = null;

        // Array of default cURL options.
        $curlOptions = array(
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_CONNECTTIMEOUT => 10, // Connect timeout in seconds
            CURLOPT_RETURNTRANSFER => false, // Streaming the return, so no need
            CURLOPT_HEADER => false, // Retrieve the received headers
            CURLOPT_USERAGENT => (string) $request->getHeader('User-Agent'),
            CURLOPT_ENCODING => '', // Supports all encodings
            CURLOPT_PORT => $request->getPort(),
            CURLOPT_HTTP_VERSION => $request->getProtocolVersion() === '1.0' ? CURL_HTTP_VERSION_1_0 : CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => array(),
            CURLOPT_HEADERFUNCTION => array($mediator, 'receiveResponseHeader')
        );

        // Enable the progress function if the 'progress' param was set
        if ($requestCurlOptions->get('progress')) {
            $curlOptions[CURLOPT_PROGRESSFUNCTION] = array($mediator, 'progress');
            $curlOptions[CURLOPT_NOPROGRESS] = false;
        }

        // Enable curl debug information if the 'debug' param was set
        if ($requestCurlOptions->get('debug')) {
            $curlOptions[CURLOPT_STDERR] = fopen('php://temp', 'r+');
            // @codeCoverageIgnoreStart
            if (false === $curlOptions[CURLOPT_STDERR]) {
                throw new RuntimeException('Unable to create a stream for CURLOPT_STDERR');
            }
            // @codeCoverageIgnoreEnd
            $curlOptions[CURLOPT_VERBOSE] = true;
        }

        // HEAD requests need no response body, everything else might
        if ($request->getMethod() != 'HEAD') {
            $curlOptions[CURLOPT_WRITEFUNCTION] = array($mediator, 'writeResponseBody');
        }

        // Account for PHP installations with safe_mode or open_basedir enabled
        // @codeCoverageIgnoreStart
        if (CurlVersion::getInstance()->get('follow_location')) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = true;
            $curlOptions[CURLOPT_MAXREDIRS] = 5;
        }
        // @codeCoverageIgnoreEnd

        // Specify settings according to the HTTP method
        switch ($request->getMethod()) {
            case 'GET':
                $curlOptions[CURLOPT_HTTPGET] = true;
                break;
            case 'HEAD':
                $curlOptions[CURLOPT_NOBODY] = true;
                break;
            case 'POST':
                $curlOptions[CURLOPT_POST] = true;

                // Special handling for POST specific fields and files
                if (count($request->getPostFiles())) {

                    $fields = $request->getPostFields()->useUrlEncoding(false)->urlEncode();
                    foreach ($request->getPostFiles() as $key => $data) {
                        $prefixKeys = count($data) > 1;
                        foreach ($data as $index => $file) {
                            // Allow multiple files in the same key
                            $fieldKey = $prefixKeys ? "{$key}[{$index}]" : $key;
                            $fields[$fieldKey] = $file->getCurlString();
                        }
                    }

                    $curlOptions[CURLOPT_POSTFIELDS] = $fields;
                    $request->removeHeader('Content-Length');

                } elseif (count($request->getPostFields())) {
                    $curlOptions[CURLOPT_POSTFIELDS] = (string) $request->getPostFields()->useUrlEncoding(true);
                    $request->removeHeader('Content-Length');
                }
                break;
            case 'PUT':
            case 'PATCH':
                if ($request->getMethod() == 'PATCH') {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                }
                $curlOptions[CURLOPT_UPLOAD] = true;
                // Let cURL handle setting the Content-Length header
                $contentLength = $request->getHeader('Content-Length');
                if ($contentLength !== null) {
                    $contentLength = (int) (string) $contentLength;
                    $curlOptions[CURLOPT_INFILESIZE] = $contentLength;
                    $tempContentLength = $contentLength;
                    $request->removeHeader('Content-Length');
                }
                break;
            default:
                $curlOptions[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
        }

        // Special handling for requests sending raw data
        if ($request instanceof EntityEnclosingRequestInterface) {

            // Don't modify POST requests using POST fields and files via cURL
            if (!isset($curlOptions[CURLOPT_POSTFIELDS])) {
                if ($request->getBody()) {
                    // Add a callback for curl to read data to send with the request
                    // only if a body was specified
                    $curlOptions[CURLOPT_READFUNCTION] = array($mediator, 'readRequestBody');
                } else {
                    // Need to remove CURLOPT_POST to prevent chunked encoding
                    unset($curlOptions[CURLOPT_POST]);
                }
            }

            // If the Expect header is not present, prevent curl from adding it
            if (!$request->hasHeader('Expect')) {
                $curlOptions[CURLOPT_HTTPHEADER][] = 'Expect:';
            }
        }

        // Set custom cURL options
        foreach ($requestCurlOptions as $key => $value) {
            if (is_numeric($key)) {
                $curlOptions[$key] = $value;
            }
        }

        // Check if any headers or cURL options are blacklisted
        $client = $request->getClient();
        if ($client) {
            $blacklist = $client->getConfig('curl.blacklist');
            if ($blacklist) {
                foreach ($blacklist as $value) {
                    if (strpos($value, 'header.') === 0) {
                        // Remove headers that may have previously been set
                        // but are supposed to be blacklisted
                        $key = substr($value, 7);
                        $request->removeHeader($key);
                        $curlOptions[CURLOPT_HTTPHEADER][] = $key . ':';
                    } else {
                        unset($curlOptions[$value]);
                    }
                }
            }
        }

        // Add any custom headers to the request. Empty headers will cause curl to
        // not send the header at all.
        foreach ($request->getHeaderLines() as $line) {
            $curlOptions[CURLOPT_HTTPHEADER][] = $line;
        }

        // Apply the options to a new cURL handle.
        $handle = curl_init();
        curl_setopt_array($handle, $curlOptions);
        $request->getParams()->set('curl.last_options', $curlOptions);

        if ($tempContentLength) {
            $request->setHeader('Content-Length', $tempContentLength);
        }

        $handle = new static($handle, $curlOptions);
        $mediator->setCurlHandle($handle);

        return $handle;
    }

    /**
     * Construct a new CurlHandle object that wraps a cURL handle
     *
     * @param resource         $handle  Configured cURL handle resource
     * @param Collection|array $options Curl options to use with the handle
     *
     * @throws InvalidArgumentException
     */
    public function __construct($handle, $options)
    {
        if (!is_resource($handle)) {
            throw new InvalidArgumentException('Invalid handle provided');
        }
        if (is_array($options)) {
            $this->options = new Collection($options);
        } elseif ($options instanceof Collection) {
            $this->options = $options;
        } else {
            throw new InvalidArgumentException('Expected array or Collection');
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
        return is_resource($this->handle);
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

        return $this->isAvailable() ? curl_errno($this->handle) : CURLE_OK;
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
     * @param int $option Option to retrieve. Pass null to retrieve all data as an array.
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
     * @param bool $asResource Set to TRUE to get an fopen resource
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
        return $this->isAvailable() ? $this->handle : null;
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

    /**
     * Update a request based on the log messages of the CurlHandle
     *
     * @param RequestInterface $request Request to update
     */
    public function updateRequestFromTransfer(RequestInterface $request)
    {
        $log = $this->getStderr(true);

        if (!$log || !$request->getResponse()) {
            return;
        }

        // Update the transfer stats of the response
        $request->getResponse()->setInfo($this->getInfo());

        // Parse the cURL stderr output for outgoing requests
        $headers = '';
        fseek($log, 0);
        while (($line = fgets($log)) !== false) {
            if ($line && $line[0] == '>') {
                $headers = substr(trim($line), 2) . "\r\n";
                while (($line = fgets($log)) !== false) {
                    if ($line[0] == '*' || $line[0] == '<') {
                        break;
                    } else {
                        $headers .= trim($line) . "\r\n";
                    }
                }
            }
        }

        // Add request headers to the request exactly as they were sent
        if ($headers) {
            $parsed = ParserRegistry::get('message')->parseRequest($headers);
            if (!empty($parsed['headers'])) {
                $request->setHeaders(array());
                foreach ($parsed['headers'] as $name => $value) {
                    $request->setHeader($name, $value);
                }
            }
            if (!empty($parsed['version'])) {
                $request->setProtocolVersion($parsed['version']);
            }
        }
    }

    /**
     * Parse the configuration and replace curl.* configurators into the
     * constant based values so it can be used elsewhere
     *
     * @param array|Collection $config The configuration we want to parse
     *
     * @return array
     */
    public static function parseCurlConfig($config)
    {
        $curlOptions = array();
        foreach ($config as $key => $value) {
            if (strpos($key, 'curl.') === 0) {
                $curlOption = substr($key, 5);
                // Convert constants represented as string to constant int values
                if (defined($curlOption)) {
                    $value = is_string($value) && defined($value) ? constant($value) : $value;
                    $curlOption = constant($curlOption);
                }
                $curlOptions[$curlOption] = $value;
            }
        }

        return $curlOptions;
    }
}
