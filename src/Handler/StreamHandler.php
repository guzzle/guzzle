<?php
namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamableInterface;

/**
 * HTTP handler that uses PHP's HTTP stream wrapper.
 */
class StreamHandler
{
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

        try {
            // Does not support the expect header.
            $request = $request->withoutHeader('Expect');
            $stream = $this->createStream($request, $options, $headers);
            return $this->createResponse($options, $headers, $stream);
        } catch (\Exception $e) {
            // Determine if the error was a networking error.
            $message = $e->getMessage();
            // This list can probably get more comprehensive.
            if (strpos($message, 'getaddrinfo') // DNS lookup failed
                || strpos($message, 'Connection refused')
            ) {
                $e = new ConnectException($e->getMessage(), $request, null, $e);
            }
            return new RejectedPromise(
                RequestException::wrapException($request, $e)
            );
        }
    }

    private function createResponse(
        array $options,
        array $hdrs,
        $stream
    ) {
        $parts = explode(' ', array_shift($hdrs), 3);
        $response = [
            'status'         => $parts[1],
            'reason'         => isset($parts[2]) ? $parts[2] : null,
            'headers'        => \GuzzleHttp\headers_from_lines($hdrs)
        ];

        $stream = $this->checkDecode($options, $response['headers'], $stream);

        // If not streaming, then drain the response into a stream.
        if (empty($options['stream'])) {
            $dest = isset($options['sink'])
                ? $options['sink']
                : fopen('php://temp', 'r+');
            $stream = $this->drain($stream, $dest);
        }

        return new FulfilledPromise(
            new Psr7\Response(
                $response['status'],
                $response['headers'],
                $stream
            )
        );
    }

    private function checkDecode(array $options, array $headers, $stream)
    {
        // Automatically decode responses when instructed.
        if (!empty($options['decode_content'])) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) == 'content-encoding') {
                    if ($value == 'gzip' || $value == 'deflate') {
                        return new Psr7\InflateStream(
                            Psr7\Stream::factory($stream)
                        );
                    }
                }
            }
        }

        return $stream;
    }

    /**
     * Drains the stream into the "sink" client option.
     *
     * @param resource                            $stream
     * @param string|resource|StreamableInterface $dest
     *
     * @return StreamableInterface
     * @throws \RuntimeException when the sink option is invalid.
     */
    private function drain($stream, $dest)
    {
        if (is_resource($stream)) {
            if (!is_resource($dest)) {
                $stream = Psr7\Stream::factory($stream);
            } else {
                stream_copy_to_stream($stream, $dest);
                fclose($stream);
                rewind($dest);
                return Psr7\Stream::factory($dest);
            }
        }

        // Stream the response into the destination stream
        $dest = is_string($dest)
            ? new Psr7\Stream(Psr7\try_fopen($dest, 'r+'))
            : Psr7\Stream::factory($dest);

        Psr7\copy_to_stream($stream, $dest);
        $dest->seek(0);
        $stream->close();

        return $dest;
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

    private function createStream(
        RequestInterface $request,
        array $options,
        &$http_response_header
    ) {
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
        $context = $this->getDefaultContext($request, $options);
        if (isset($options['stream_context'])) {
            $context = array_replace_recursive(
                $context,
                $options['stream_context']
            );
        }

        if (!empty($options)) {
            foreach ($options as $key => $value) {
                $method = "add_{$key}";
                if (isset($methods[$method])) {
                    $this->{$method}($request, $context, $value, $params);
                }
            }
        }

        $context = $this->createResource(
            function () use ($context, $params) {
                return stream_context_create($context, $params);
            }
        );

        return $this->createResource(
            function () use ($request, &$http_response_header, $context) {
                return fopen($request->getUri(), 'r', null, $context);
            }
        );
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

    private function add_proxy(RequestInterface $request, &$options, $value, &$params)
    {
        if (!is_array($value)) {
            $options['http']['proxy'] = $value;
        } else {
            $scheme = $request->getUri()->getScheme();
            if (isset($value[$scheme])) {
                $options['http']['proxy'] = $value[$scheme];
            }
        }
    }

    private function add_timeout(RequestInterface $request, &$options, $value, &$params)
    {
        $options['http']['timeout'] = $value;
    }

    private function add_verify(RequestInterface $request, &$options, $value, &$params)
    {
        if ($value === true) {
            // PHP 5.6 or greater will find the system cert by default. When
            // < 5.6, use the Guzzle bundled cacert.
            if (PHP_VERSION_ID < 50600) {
                $options['ssl']['cafile'] = \GuzzleHttp\default_ca_bundle();
            }
        } elseif (is_string($value)) {
            $options['ssl']['cafile'] = $value;
            if (!file_exists($value)) {
                throw new \RuntimeException("SSL CA bundle not found: $value");
            }
        } elseif ($value === false) {
            $options['ssl']['verify_peer'] = false;
            return;
        } else {
            throw new \InvalidArgumentException('Invalid verify request option');
        }

        $options['ssl']['verify_peer'] = true;
        $options['ssl']['allow_self_signed'] = true;
    }

    private function add_cert(RequestInterface $request, &$options, $value, &$params)
    {
        if (is_array($value)) {
            $options['ssl']['passphrase'] = $value[1];
            $value = $value[0];
        }

        if (!file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }

        $options['ssl']['local_cert'] = $value;
    }

    private function add_progress(RequestInterface $request, &$options, $value, &$params)
    {
        $this->addNotification(
            $params,
            function ($code, $_, $_, $_, $transferred, $total) use ($value) {
                if ($code == STREAM_NOTIFY_PROGRESS) {
                    $value($total, $transferred, null, null);
                }
            }
        );
    }

    private function add_debug(RequestInterface $request, &$options, $value, &$params)
    {
        if ($value === false) {
            return;
        }

        static $map = [
            STREAM_NOTIFY_CONNECT       => 'CONNECT',
            STREAM_NOTIFY_AUTH_REQUIRED => 'AUTH_REQUIRED',
            STREAM_NOTIFY_AUTH_RESULT   => 'AUTH_RESULT',
            STREAM_NOTIFY_MIME_TYPE_IS  => 'MIME_TYPE_IS',
            STREAM_NOTIFY_FILE_SIZE_IS  => 'FILE_SIZE_IS',
            STREAM_NOTIFY_REDIRECTED    => 'REDIRECTED',
            STREAM_NOTIFY_PROGRESS      => 'PROGRESS',
            STREAM_NOTIFY_FAILURE       => 'FAILURE',
            STREAM_NOTIFY_COMPLETED     => 'COMPLETED',
            STREAM_NOTIFY_RESOLVE       => 'RESOLVE',
        ];
        static $args = ['severity', 'message', 'message_code',
            'bytes_transferred', 'bytes_max'];

        $value = \GuzzleHttp\debug_resource($value);
        $ident = $request->getMethod() . ' ' . $request->getUri();
        $this->addNotification(
            $params,
            function () use ($ident, $value, $map, $args) {
                $passed = func_get_args();
                $code = array_shift($passed);
                fprintf($value, '<%s> [%s] ', $ident, $map[$code]);
                foreach (array_filter($passed) as $i => $v) {
                    fwrite($value, $args[$i] . ': "' . $v . '" ');
                }
                fwrite($value, "\n");
            }
        );
    }

    private function addNotification(array &$params, callable $notify)
    {
        // Wrap the existing function if needed.
        if (!isset($params['notification'])) {
            $params['notification'] = $notify;
        } else {
            $params['notification'] = $this->callArray([
                $params['notification'],
                $notify
            ]);
        }
    }

    private function callArray(array $functions)
    {
        return function () use ($functions) {
            $args = func_get_args();
            foreach ($functions as $fn) {
                call_user_func_array($fn, $args);
            }
        };
    }
}
