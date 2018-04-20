<?php
namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * HTTP handler that uses PHP's HTTP stream wrapper.
 */
class StreamHandler
{
    private $lastHeaders = [];

    /**
     * Sends an HTTP request.
     *
     * @param RequestInterface $request Request to send.
     * @param array            $options Request transfer options.
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        // Sleep if there is a delay specified.
        if (isset($options['delay'])) {
            usleep($options['delay'] * 1000);
        }

        $startTime = isset($options['on_stats']) ? microtime(true) : null;

        try {
            // Does not support the expect header.
            $request = $request->withoutHeader('Expect');

            // Append a content-length header if body size is zero to match
            // cURL's behavior.
            if (0 === $request->getBody()->getSize()) {
                $request = $request->withHeader('Content-Length', 0);
            }

            return $this->createResponse(
                $request,
                $options,
                $this->createStream($request, $options),
                $startTime
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Determine if the error was a networking error.
            $message = $e->getMessage();
            // This list can probably get more comprehensive.
            if (strpos($message, 'getaddrinfo') // DNS lookup failed
                || strpos($message, 'Connection refused')
                || strpos($message, "couldn't connect to host") // error on HHVM
                || strpos($message, "connection attempt failed")
            ) {
                $e = new ConnectException($e->getMessage(), $request, $e);
            }
            $e = RequestException::wrapException($request, $e);
            $this->invokeStats($options, $request, $startTime, null, $e);

            return \GuzzleHttp\Promise\rejection_for($e);
        }
    }

    private function invokeStats(
        array $options,
        RequestInterface $request,
        $startTime,
        ResponseInterface $response = null,
        $error = null
    ) {
        if (isset($options['on_stats'])) {
            $stats = new TransferStats(
                $request,
                $response,
                microtime(true) - $startTime,
                $error,
                []
            );
            call_user_func($options['on_stats'], $stats);
        }
    }

    private function createResponse(
        RequestInterface $request,
        array $options,
        $stream,
        $startTime
    ) {
        $hdrs = $this->lastHeaders;
        $this->lastHeaders = [];
        $parts = explode(' ', array_shift($hdrs), 3);
        $ver = explode('/', $parts[0])[1];
        $status = $parts[1];
        $reason = isset($parts[2]) ? $parts[2] : null;
        $headers = \GuzzleHttp\headers_from_lines($hdrs);
        list($stream, $headers) = $this->checkDecode($options, $headers, $stream);
        $stream = Psr7\stream_for($stream);
        $sink = $stream;

        if (strcasecmp('HEAD', $request->getMethod())) {
            $sink = $this->createSink($stream, $options);
        }

        $response = new Psr7\Response($status, $headers, $sink, $ver, $reason);

        if (isset($options['on_headers'])) {
            try {
                $options['on_headers']($response);
            } catch (\Exception $e) {
                $msg = 'An error was encountered during the on_headers event';
                $ex = new RequestException($msg, $request, $response, $e);
                return \GuzzleHttp\Promise\rejection_for($ex);
            }
        }

        // Do not drain when the request is a HEAD request because they have
        // no body.
        if ($sink !== $stream) {
            $this->drain(
                $stream,
                $sink,
                $response->getHeaderLine('Content-Length')
            );
        }

        $this->invokeStats($options, $request, $startTime, $response, null);

        return new FulfilledPromise($response);
    }

    private function createSink(StreamInterface $stream, array $options)
    {
        if (!empty($options['stream'])) {
            return $stream;
        }

        $sink = isset($options['sink'])
            ? $options['sink']
            : fopen('php://temp', 'r+');

        return is_string($sink)
            ? new Psr7\LazyOpenStream($sink, 'w+')
            : Psr7\stream_for($sink);
    }

    private function checkDecode(array $options, array $headers, $stream)
    {
        // Automatically decode responses when instructed.
        if (!empty($options['decode_content'])) {
            $normalizedKeys = \GuzzleHttp\normalize_header_keys($headers);
            if (isset($normalizedKeys['content-encoding'])) {
                $encoding = $headers[$normalizedKeys['content-encoding']];
                if ($encoding[0] === 'gzip' || $encoding[0] === 'deflate') {
                    $stream = new Psr7\InflateStream(
                        Psr7\stream_for($stream)
                    );
                    $headers['x-encoded-content-encoding']
                        = $headers[$normalizedKeys['content-encoding']];
                    // Remove content-encoding header
                    unset($headers[$normalizedKeys['content-encoding']]);
                    // Fix content-length header
                    if (isset($normalizedKeys['content-length'])) {
                        $headers['x-encoded-content-length']
                            = $headers[$normalizedKeys['content-length']];

                        $length = (int) $stream->getSize();
                        if ($length === 0) {
                            unset($headers[$normalizedKeys['content-length']]);
                        } else {
                            $headers[$normalizedKeys['content-length']] = [$length];
                        }
                    }
                }
            }
        }

        return [$stream, $headers];
    }

    /**
     * Drains the source stream into the "sink" client option.
     *
     * @param StreamInterface $source
     * @param StreamInterface $sink
     * @param string          $contentLength Header specifying the amount of
     *                                       data to read.
     *
     * @return StreamInterface
     * @throws \RuntimeException when the sink option is invalid.
     */
    private function drain(
        StreamInterface $source,
        StreamInterface $sink,
        $contentLength
    ) {
        // If a content-length header is provided, then stop reading once
        // that number of bytes has been read. This can prevent infinitely
        // reading from a stream when dealing with servers that do not honor
        // Connection: Close headers.
        Psr7\copy_to_stream(
            $source,
            $sink,
            (strlen($contentLength) > 0 && (int) $contentLength > 0) ? (int) $contentLength : -1
        );

        $sink->seek(0);
        $source->close();

        return $sink;
    }

    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable $callback Callable that returns stream resource
     *
     * @return resource
     * @throws \RuntimeException on error
     */
    private function createResource(callable $callback)
    {
        $errors = null;
        set_error_handler(function ($_, $msg, $file, $line) use (&$errors) {
            $errors[] = [
                'message' => $msg,
                'file'    => $file,
                'line'    => $line
            ];
            return true;
        });

        $resource = $callback();
        restore_error_handler();

        if (!$resource) {
            $message = 'Error creating resource: ';
            foreach ($errors as $err) {
                foreach ($err as $key => $value) {
                    $message .= "[$key] $value" . PHP_EOL;
                }
            }
            throw new \RuntimeException(trim($message));
        }

        return $resource;
    }

    private function createStream(RequestInterface $request, array $options)
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        // HTTP/1.1 streams using the PHP stream wrapper require a
        // Connection: close header
        if ($request->getProtocolVersion() == '1.1'
            && !$request->hasHeader('Connection')
        ) {
            $request = $request->withHeader('Connection', 'close');
        }

        // Ensure SSL is verified by default
        if (!isset($options['verify'])) {
            $options['verify'] = true;
        }

        $params = [];
        $context = $this->getDefaultContext($request);

        if (isset($options['on_headers']) && !is_callable($options['on_headers'])) {
            throw new \InvalidArgumentException('on_headers must be callable');
        }

        if (!empty($options)) {
            foreach ($options as $key => $value) {
                $method = "add_{$key}";
                if (isset($methods[$method])) {
                    $this->{$method}($request, $context, $value, $params);
                }
            }
        }

        if (isset($options['stream_context'])) {
            if (!is_array($options['stream_context'])) {
                throw new \InvalidArgumentException('stream_context must be an array');
            }
            $context = array_replace_recursive(
                $context,
                $options['stream_context']
            );
        }

        // Microsoft NTLM authentication only supported with curl handler
        if (isset($options['auth'])
            && is_array($options['auth'])
            && isset($options['auth'][2])
            && 'ntlm' == $options['auth'][2]
        ) {
            throw new \InvalidArgumentException('Microsoft NTLM authentication only supported with curl handler');
        }

        $uri = $this->resolveHost($request, $options);

        $context = $this->createResource(
            function () use ($context, $params) {
                return stream_context_create($context, $params);
            }
        );

        return $this->createResource(
            function () use ($uri, &$http_response_header, $context, $options) {
                $resource = fopen((string) $uri, 'r', null, $context);
                $this->lastHeaders = $http_response_header;

                if (isset($options['read_timeout'])) {
                    $readTimeout = $options['read_timeout'];
                    $sec = (int) $readTimeout;
                    $usec = ($readTimeout - $sec) * 100000;
                    stream_set_timeout($resource, $sec, $usec);
                }

                return $resource;
            }
        );
    }

    private function resolveHost(RequestInterface $request, array $options)
    {
        $uri = $request->getUri();

        if (isset($options['force_ip_resolve']) && !filter_var($uri->getHost(), FILTER_VALIDATE_IP)) {
            if ('v4' === $options['force_ip_resolve']) {
                $records = dns_get_record($uri->getHost(), DNS_A);
                if (!isset($records[0]['ip'])) {
                    throw new ConnectException(sprintf("Could not resolve IPv4 address for host '%s'", $uri->getHost()), $request);
                }
                $uri = $uri->withHost($records[0]['ip']);
            } elseif ('v6' === $options['force_ip_resolve']) {
                $records = dns_get_record($uri->getHost(), DNS_AAAA);
                if (!isset($records[0]['ipv6'])) {
                    throw new ConnectException(sprintf("Could not resolve IPv6 address for host '%s'", $uri->getHost()), $request);
                }
                $uri = $uri->withHost('[' . $records[0]['ipv6'] . ']');
            }
        }

        return $uri;
    }

    private function getDefaultContext(RequestInterface $request)
    {
        $headers = '';
        foreach ($request->getHeaders() as $name => $value) {
            foreach ($value as $val) {
                $headers .= "$name: $val\r\n";
            }
        }

        $context = [
            'http' => [
                'method'           => $request->getMethod(),
                'header'           => $headers,
                'protocol_version' => $request->getProtocolVersion(),
                'ignore_errors'    => true,
                'follow_location'  => 0,
            ],
        ];

        $body = (string) $request->getBody();

        if (!empty($body)) {
            $context['http']['content'] = $body;
            // Prevent the HTTP handler from adding a Content-Type header.
            if (!$request->hasHeader('Content-Type')) {
                $context['http']['header'] .= "Content-Type:\r\n";
            }
        }

        $context['http']['header'] = rtrim($context['http']['header']);

        return $context;
    }
}
