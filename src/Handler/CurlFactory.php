<?php
namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\LazyOpenStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamableInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Creates curl resources from a request
 */
class CurlFactory
{
    /**
     * Creates a cURL handle, header resource, and body resource based on a
     * transaction.
     *
     * @param RequestInterface $request Request
     * @param array            $options Transfer options
     * @param null|resource    $handle  Options cURL handle to modify
     *
     * @return array Returns an array of the curl handle, headers array, and
     *               response body handle.
     * @throws \RuntimeException when an option cannot be applied
     */
    public function __invoke(
        RequestInterface $request,
        array $options,
        $handle = null
    ) {
        $headers = [];
        $conf = $this->getDefaultOptions($request, $headers);
        $this->applyMethod($request, $options, $conf);
        $this->applyHandlerOptions($request, $options, $conf);
        $this->applyHeaders($request, $conf);
        unset($conf['_headers']);

        if (isset($options['curl']['body_as_string'])) {
            $options['_body_as_string'] = $options['curl']['body_as_string'];
            unset($options['curl']['body_as_string']);
        }

        // Add handler options from the request configuration options
        if (isset($options['curl'])) {
            $conf += $options['curl'];
        }

        $handle = $handle ?: curl_init();
        $body = $this->getOutputBody($request, $options, $conf);
        curl_setopt_array($handle, $conf);

        return [$handle, &$headers, $body];
    }

    /**
     * Creates a response hash from a cURL result.
     *
     * @param callable            $handler  Handler that was used.
     * @param RequestInterface    $request  Request that sent.
     * @param array               $options  Request transfer options.
     * @param array               $response Response hash.
     * @param array               $headers  Headers received during transfer.
     * @param StreamableInterface $body     Response body.
     *
     * @return ResponseInterface
     */
    public static function createResponse(
        callable $handler,
        RequestInterface $request,
        array $options,
        array $response,
        array $headers,
        StreamableInterface $body
    ) {
        if (isset($response['transfer_stats']['url'])) {
            $response['effective_url'] = $response['transfer_stats']['url'];
        }

        if (!empty($headers)) {
            $startLine = explode(' ', array_shift($headers), 3);
            $headerList = \GuzzleHttp\headers_from_lines($headers);
            $response['headers'] = $headerList;
            $response['status'] = isset($startLine[1]) ? (int) $startLine[1] : null;
            $response['reason'] = isset($startLine[2]) ? $startLine[2] : null;
            $response['body'] = $body;
            $response['body']->rewind();
        }

        if (!empty($response['curl']['errno']) || !isset($response['status'])) {
            return self::createErrorResponse($handler, $request, $options, $response);
        }

        return new Response(
            $response['status'],
            $response['headers'],
            $response['body'],
            $response['reason']
        );
    }

    private static function createErrorResponse(
        callable $handler,
        RequestInterface $request,
        array $options,
        array $response
    ) {
        static $connectionErrors = [
            CURLE_OPERATION_TIMEOUTED  => true,
            CURLE_COULDNT_RESOLVE_HOST => true,
            CURLE_COULDNT_CONNECT      => true,
            CURLE_SSL_CONNECT_ERROR    => true,
            CURLE_GOT_NOTHING          => true,
        ];

        // Retry when nothing is present or when curl failed to rewind.
        if (!isset($response['err_message'])
            && (empty($response['curl']['errno'])
                || $response['curl']['errno'] == 65)
        ) {
            return self::retryFailedRewind($handler, $request, $options, $response);
        }

        $message = isset($response['err_message'])
            ? $response['err_message']
            : sprintf('cURL error %s: %s',
                $response['curl']['errno'],
                isset($response['curl']['error'])
                    ? $response['curl']['error']
                    : 'See http://curl.haxx.se/libcurl/c/libcurl-errors.html');

        if (isset($response['curl']['errno'])
            && isset($connectionErrors[$response['curl']['errno']])
        ) {
            $error = new ConnectException($message, $request);
        } else {
            $error = new RequestException(
                $message,
                $request,
                new Response(
                    isset($response['status']) ? $response['status'] : 200,
                    isset($response['headers']) ? $response['headers'] : [],
                    isset($response['body']) ? $response['body'] : null,
                    isset($response['reason']) ? $response['reason'] : null
                )
            );
        }

        return new RejectedPromise($error);
    }

    private function getOutputBody(RequestInterface $request, array $options, array &$conf)
    {
        // Determine where the body of the response (if any) will be streamed.
        if (isset($conf[CURLOPT_WRITEFUNCTION])) {
            return $options['sink'];
        }

        if (isset($conf[CURLOPT_FILE])) {
            return $conf[CURLOPT_FILE];
        }

        if ($request->getMethod() !== 'HEAD') {
            // Create a default body if one was not provided
            return $conf[CURLOPT_FILE] = fopen('php://temp', 'w+');
        }

        return null;
    }

    private function getDefaultOptions(RequestInterface $request, array &$headers)
    {
        $url = (string) $request->getUri();
        $startingResponse = false;

        $options = [
            '_headers'             => $request->getHeaders(),
            CURLOPT_CUSTOMREQUEST  => $request->getMethod(),
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => 150,
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$headers, &$startingResponse) {
                $value = trim($h);
                if ($value === '') {
                    $startingResponse = true;
                } elseif ($startingResponse) {
                    $startingResponse = false;
                    $headers = [$value];
                } else {
                    $headers[] = $value;
                }
                return strlen($h);
            },
        ];

        $options[CURLOPT_HTTP_VERSION] = $request->getProtocolVersion() == 1.1
            ? CURL_HTTP_VERSION_1_1
            : CURL_HTTP_VERSION_1_0;

        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return $options;
    }

    private function applyMethod(RequestInterface $request, array $options, array &$conf)
    {
        $body = $request->getBody();
        $size = $body->getSize();

        if ($size === null || $size > 0) {
            $this->applyBody($request, $options, $conf);
            return;
        }

        $method = $request->getMethod();
        if ($method === 'PUT' || $method === 'POST') {
            // See http://tools.ietf.org/html/rfc7230#section-3.3.2
            if (!$request->hasHeader('Content-Length')) {
                $conf[CURLOPT_HTTPHEADER][] = 'Content-Length: 0';
            }
        } elseif ($method === 'HEAD') {
            $conf[CURLOPT_NOBODY] = true;
            unset(
                $conf[CURLOPT_WRITEFUNCTION],
                $conf[CURLOPT_READFUNCTION],
                $conf[CURLOPT_FILE],
                $conf[CURLOPT_INFILE]
            );
        }
    }

    private function applyBody(RequestInterface $request, array $options, array &$conf)
    {
        $size = $request->hasHeader('Content-Length')
            ? (int) $request->getHeader('Content-Length')
            : $request->getBody()->getSize();

        // Send the body as a string if the size is less than 1MB OR if the
        // [curl][body_as_string] request value is set.
        if (($size !== null && $size < 1000000) ||
            !empty($options['_body_as_string'])
        ) {
            $conf[CURLOPT_POSTFIELDS] = (string) $request->getBody();
            // Don't duplicate the Content-Length header
            $this->removeHeader('Content-Length', $conf);
            $this->removeHeader('Transfer-Encoding', $conf);
        } else {
            $conf[CURLOPT_UPLOAD] = true;
            if ($size !== null) {
                // Let cURL handle setting the Content-Length header
                $conf[CURLOPT_INFILESIZE] = $size;
                $this->removeHeader('Content-Length', $conf);
            }
            $this->addStreamingBody($request, $conf);
        }

        // If the Expect header is not present, prevent curl from adding it
        if (!$request->hasHeader('Expect')) {
            $conf[CURLOPT_HTTPHEADER][] = 'Expect:';
        }

        // cURL sometimes adds a content-type by default. Prevent this.
        if (!$request->hasHeader('Content-Type')) {
            $conf[CURLOPT_HTTPHEADER][] = 'Content-Type:';
        }
    }

    private function addStreamingBody(RequestInterface $request, array &$conf)
    {
        $body = $request->getBody();
        $size = $body->getSize();

        if ($size > 0 || $size === null) {
            $conf[CURLOPT_READFUNCTION] = function ($ch, $fd, $length) use ($body) {
                return (string) $body->read($length);
            };
            if ($size !== null && !isset($conf[CURLOPT_INFILESIZE])) {
                $conf[CURLOPT_INFILESIZE] = $size;
            }
        }
    }

    private function applyHeaders(RequestInterface $request, array &$conf)
    {
        foreach ($conf['_headers'] as $name => $values) {
            foreach ($values as $value) {
                $conf[CURLOPT_HTTPHEADER][] = "$name: $value";
            }
        }

        // Remove the Accept header if one was not set
        if (!$request->hasHeader('Accept')) {
            $conf[CURLOPT_HTTPHEADER][] = 'Accept:';
        }
    }

    /**
     * Remove a header from the options array.
     *
     * @param string $name    Case-insensitive header to remove
     * @param array  $options Array of options to modify
     */
    private function removeHeader($name, array &$options)
    {
        foreach (array_keys($options['_headers']) as $key) {
            if (!strcasecmp($key, $name)) {
                unset($options['_headers'][$key]);
                return;
            }
        }
    }

    /**
     * Applies an array of request client options to a the options array.
     *
     * This method uses a large switch rather than double-dispatch to save on
     * high overhead of calling functions in PHP.
     *
     * @param RequestInterface $request Request to send
     * @param array            $options Request transfer options.
     * @param array            $conf    cURL configuration options.
     */
    private function applyHandlerOptions(
        RequestInterface $request,
        array $options,
        array &$conf
    ) {
        if (isset($options['verify'])) {
            if ($options['verify'] === false) {
                unset($conf[CURLOPT_CAINFO]);
                $conf[CURLOPT_SSL_VERIFYHOST] = 0;
                $conf[CURLOPT_SSL_VERIFYPEER] = false;
            } else {
                $conf[CURLOPT_SSL_VERIFYHOST] = 2;
                $conf[CURLOPT_SSL_VERIFYPEER] = true;
                if (is_string($options['verify'])) {
                    $conf[CURLOPT_CAINFO] = $options['verify'];
                    if (!file_exists($options['verify'])) {
                        throw new \InvalidArgumentException(
                            "SSL CA bundle not found: {$options['verify']}"
                        );
                    }
                }
            }
        }

        if (!empty($options['decode_content'])) {
            $accept = $request->getHeader('Accept-Encoding');
            if ($accept) {
                $conf[CURLOPT_ENCODING] = $accept;
            } else {
                $conf[CURLOPT_ENCODING] = '';
                // Don't let curl send the header over the wire
                $conf[CURLOPT_HTTPHEADER][] = 'Accept-Encoding:';
            }
        }

        // Backwards compat with "save_to" => "sink"
        if (isset($options['save_to'])) {
            $options['sink'] = $options['save_to'];
        }

        if (isset($options['sink'])) {
            $sink = $options['sink'];
            $sink = is_string($sink)
                ? new LazyOpenStream($sink, 'w+')
                : \GuzzleHttp\Psr7\stream_for($sink);
            $conf[CURLOPT_WRITEFUNCTION] = function ($ch, $write) use ($sink) {
                return $sink->write($write);
            };
        }

        if (isset($options['timeout'])) {
            if (defined('CURLOPT_TIMEOUT_MS')) {
                $conf[CURLOPT_TIMEOUT_MS] = $options['timeout'] * 1000;
            } else {
                $conf[CURLOPT_TIMEOUT] = $options['timeout'];
            }
        }

        if (isset($options['connect_timeout'])) {
            if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
                $conf[CURLOPT_CONNECTTIMEOUT_MS] = $options['connect_timeout'] * 1000;
            } else {
                $conf[CURLOPT_CONNECTTIMEOUT] = $options['connect_timeout'];
            }
        }

        if (isset($options['proxy'])) {
            if (!is_array($options['proxy'])) {
                $conf[CURLOPT_PROXY] = $options['proxy'];
            } elseif ($scheme = $request->getUri()->getScheme()) {
                if (isset($options['proxy'][$scheme])) {
                    $conf[CURLOPT_PROXY] = $options['proxy'][$scheme];
                }
            }
        }

        if (isset($options['cert'])) {
            $cert = $options['cert'];
            if (is_array($cert)) {
                $conf[CURLOPT_SSLCERTPASSWD] = $cert[1];
                $cert = $cert[0];
            }
            if (!file_exists($cert)) {
                throw new \InvalidArgumentException(
                    "SSL certificate not found: {$cert}"
                );
            }
            $conf[CURLOPT_SSLCERT] = $cert;
        }

        if (isset($options['ssl_key'])) {
            $sslKey = $options['ssl_key'];
            if (is_array($sslKey)) {
                $conf[CURLOPT_SSLKEYPASSWD] = $sslKey[1];
                $sslKey = $sslKey[0];
            }
            if (!file_exists($sslKey)) {
                throw new \InvalidArgumentException(
                    "SSL private key not found: {$sslKey}"
                );
            }
            $conf[CURLOPT_SSLKEY] = $sslKey;
        }

        if (isset($options['progress'])) {
            $progress = $options['progress'];
            if (!is_callable($progress)) {
                throw new \InvalidArgumentException(
                    'progress client option must be callable'
                );
            }
            $conf[CURLOPT_NOPROGRESS] = false;
            $conf[CURLOPT_PROGRESSFUNCTION] = function () use ($progress) {
                $args = func_get_args();
                // PHP 5.5 pushed the handle onto the start of the args
                if (is_resource($args[0])) {
                    array_shift($args);
                }
                call_user_func_array($progress, $args);
            };
        }

        if (!empty($options['debug'])) {
            $conf[CURLOPT_STDERR] = \GuzzleHttp\debug_resource($options['debug']);
            $conf[CURLOPT_VERBOSE] = true;
        }
    }

    /**
     * This function ensures that a response was set on a transaction. If one
     * was not set, then the request is retried if possible. This error
     * typically means you are sending a payload, curl encountered a
     * "Connection died, retrying a fresh connect" error, tried to rewind the
     * stream, and then encountered a "necessary data rewind wasn't possible"
     * error, causing the request to be sent through curl_multi_info_read()
     * without an error status.
     *
     * @param callable         $handler  Handler that will retry.
     * @param RequestInterface $request  Request that was sent.
     * @param array            $options  Request options.
     * @param array            $response Response hash.
     *
     * @return PromiseInterface
     */
    private static function retryFailedRewind(
        callable $handler,
        RequestInterface $request,
        array $options,
        array $response
    ) {
        if (!$request->getBody()->rewind()) {
            $response['err_message'] = 'The connection unexpectedly failed '
                . 'without providing an error. The request would have been '
                . 'retried, but attempting to rewind the request body failed.';
            return self::createErrorResponse($handler, $request, $options, $response);
        }

        // Retry no more than 3 times before giving up.
        if (!isset($options['curl']['retries'])) {
            $options['curl']['retries'] = 1;
        } elseif ($options['curl']['retries'] == 2) {
            $response['err_message'] = 'The cURL request was retried 3 times '
                . 'and did not succeed. cURL was unable to rewind the body of '
                . 'the request and subsequent retries resulted in the same '
                . 'error. Turn on the debug option to see what went wrong. '
                . 'See https://bugs.php.net/bug.php?id=47204 for more information.';
            return self::createErrorResponse($handler, $request, $options, $response);
        } else {
            $options['curl']['retries']++;
        }

        return $handler($request, $options);
    }
}
