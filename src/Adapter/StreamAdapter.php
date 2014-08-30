<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\AdapterException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\AbstractMessage;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\InflateStream;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Stream\LazyOpenStream;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\Utils;

/**
 * HTTP adapter that uses PHP's HTTP stream wrapper.
 *
 * When using the StreamAdapter, custom stream context options can be specified
 * using the **stream_context** option in a request's **config** option. The
 * structure of the "stream_context" option is an associative array where each
 * key is a transport name and each option is an associative array of options.
 */
class StreamAdapter implements AdapterInterface
{
    /** @var MessageFactoryInterface */
    private $messageFactory;

    /**
     * @param MessageFactoryInterface $messageFactory
     */
    public function __construct(MessageFactoryInterface $messageFactory)
    {
        $this->messageFactory = $messageFactory;
    }

    public function send(TransactionInterface $transaction)
    {
        // HTTP/1.1 streams using the PHP stream wrapper require a
        // Connection: close header. Setting here so that it is added before
        // emitting the request.before_send event.
        $request = $transaction->getRequest();
        if ($request->getProtocolVersion() == '1.1' &&
            !$request->hasHeader('Connection')
        ) {
            $transaction->getRequest()->setHeader('Connection', 'close');
        }

        RequestEvents::emitBefore($transaction);
        if (!$transaction->getResponse()) {
            $this->createResponse($transaction);
            RequestEvents::emitComplete($transaction);
        }

        return $transaction->getResponse();
    }

    private function createResponse(TransactionInterface $transaction)
    {
        $request = $transaction->getRequest();
        $stream = $this->createStream($request, $http_response_header);
        $this->createResponseObject(
            $request,
            $http_response_header,
            $transaction,
            new Stream($stream)
        );
    }

    private function createResponseObject(
        RequestInterface $request,
        array $headers,
        TransactionInterface $transaction,
        StreamInterface $stream
    ) {
        $parts = explode(' ', array_shift($headers), 3);
        $options = ['protocol_version' => substr($parts[0], -3)];

        if (isset($parts[2])) {
            $options['reason_phrase'] = $parts[2];
        }

        $response = $this->messageFactory->createResponse(
            $parts[1],
            $this->headersFromLines($headers),
            null,
            $options
        );

        // Automatically decode responses when instructed.
        if ($request->getConfig()->get('decode_content')) {
            switch ($response->getHeader('Content-Encoding')) {
                case 'gzip':
                case 'deflate':
                    $stream = new InflateStream($stream);
                    break;
            }
        }

        // Drain the stream immediately if 'stream' was not enabled.
        if (!$request->getConfig()['stream']) {
            $stream = $this->getSaveToBody($request, $stream);
        }

        $response->setBody($stream);
        $transaction->setResponse($response);
        RequestEvents::emitHeaders($transaction);

        return $response;
    }

    /**
     * Drain the stream into the destination stream
     */
    private function getSaveToBody(
        RequestInterface $request,
        StreamInterface $stream
    ) {
        if ($saveTo = $request->getConfig()['save_to']) {
            // Stream the response into the destination stream
            $saveTo = is_string($saveTo)
                ? new Stream(Utils::open($saveTo, 'r+'))
                : Stream::factory($saveTo);
        } else {
            // Stream into the default temp stream
            $saveTo = Stream::factory();
        }

        Utils::copyToStream($stream, $saveTo);
        $saveTo->seek(0);
        $stream->close();

        return $saveTo;
    }

    private function headersFromLines(array $lines)
    {
        $responseHeaders = [];

        foreach ($lines as $line) {
            $headerParts = explode(':', $line, 2);
            $responseHeaders[$headerParts[0]][] = isset($headerParts[1])
                ? trim($headerParts[1])
                : '';
        }

        return $responseHeaders;
    }

    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable         $callback Callable that returns stream resource
     * @param RequestInterface $request  Request used when throwing exceptions
     * @param array            $options  Options used when throwing exceptions
     *
     * @return resource
     * @throws RequestException on error
     */
    private function createResource(callable $callback, RequestInterface $request, $options)
    {
        // Turn off error reporting while we try to initiate the request
        $level = error_reporting(0);
        $resource = call_user_func($callback);
        error_reporting($level);

        // If the resource could not be created, then grab the last error and
        // throw an exception.
        if (!is_resource($resource)) {
            $message = 'Error creating resource. [url] ' . $request->getUrl() . ' ';
            if (isset($options['http']['proxy'])) {
                $message .= "[proxy] {$options['http']['proxy']} ";
            }
            foreach ((array) error_get_last() as $key => $value) {
                $message .= "[{$key}] {$value} ";
            }
            throw new RequestException(trim($message), $request);
        }

        return $resource;
    }

    /**
     * Create the stream for the request with the context options.
     *
     * @param RequestInterface $request              Request being sent
     * @param mixed            $http_response_header Populated by stream wrapper
     *
     * @return resource
     */
    private function createStream(
        RequestInterface $request,
        &$http_response_header
    ) {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        $params = [];
        $options = $this->getDefaultOptions($request);
        foreach ($request->getConfig()->toArray() as $key => $value) {
            $method = "add_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $options, $value, $params);
            }
        }

        $this->applyCustomOptions($request, $options);
        $context = $this->createStreamContext($request, $options, $params);

        return $this->createStreamResource(
            $request,
            $options,
            $context,
            $http_response_header
        );
    }

    private function getDefaultOptions(RequestInterface $request)
    {
        $headers = AbstractMessage::getHeadersAsString($request);

        $context = [
            'http' => [
                'method'           => $request->getMethod(),
                'header'           => trim($headers),
                'protocol_version' => $request->getProtocolVersion(),
                'ignore_errors'    => true,
                'follow_location'  => 0
            ]
        ];

        if ($body = $request->getBody()) {
            $context['http']['content'] = (string) $body;
            // Prevent the HTTP adapter from adding a Content-Type header.
            if (!$request->hasHeader('Content-Type')) {
                $context['http']['header'] .= "\r\nContent-Type:";
            }
        }

        return $context;
    }

    private function add_proxy(RequestInterface $request, &$options, $value, &$params)
    {
        if (!is_array($value)) {
            $options['http']['proxy'] = $value;
        } else {
            $scheme = $request->getScheme();
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
        if ($value === true || is_string($value)) {
            $options['http']['verify_peer'] = true;
            if ($value !== true) {
                if (!file_exists($value)) {
                    throw new \RuntimeException("SSL certificate authority file not found: {$value}");
                }
                $options['http']['allow_self_signed'] = true;
                $options['http']['cafile'] = $value;
            }
        } elseif ($value === false) {
            $options['http']['verify_peer'] = false;
        }
    }

    private function add_cert(RequestInterface $request, &$options, $value, &$params)
    {
        if (is_array($value)) {
            $options['http']['passphrase'] = $value[1];
            $value = $value[0];
        }

        if (!file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }

        $options['http']['local_cert'] = $value;
    }

    private function add_debug(RequestInterface $request, &$options, $value, &$params)
    {
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
            STREAM_NOTIFY_RESOLVE       => 'RESOLVE'
        ];

        static $args = ['severity', 'message', 'message_code',
            'bytes_transferred', 'bytes_max'];

        if (!is_resource($value)) {
            $value = fopen('php://output', 'w');
        }

        $params['notification'] = function () use ($request, $value, $map, $args) {
            $passed = func_get_args();
            $code = array_shift($passed);
            fprintf($value, '<%s> [%s] ', $request->getUrl(), $map[$code]);
            foreach (array_filter($passed) as $i => $v) {
                fwrite($value, $args[$i] . ': "' . $v . '" ');
            }
            fwrite($value, "\n");
        };
    }

    private function applyCustomOptions(
        RequestInterface $request,
        array &$options
    ) {
        // Overwrite any generated options with custom options
        if ($custom = $request->getConfig()['stream_context']) {
            if (!is_array($custom)) {
                throw new AdapterException('stream_context must be an array');
            }
            $options = array_replace_recursive($options, $custom);
        }
    }

    private function createStreamContext(
        RequestInterface $request,
        array $options,
        array $params
    ) {
        return $this->createResource(
            function () use ($request, $options, $params) {
                return stream_context_create($options, $params);
            },
            $request,
            $options
        );
    }

    private function createStreamResource(
        RequestInterface $request,
        array $options,
        $context,
        &$http_response_header
    ) {
        $url = $request->getUrl();

        return $this->createResource(
            function () use ($url, &$http_response_header, $context) {
                if (false === strpos($url, 'http')) {
                    trigger_error("URL is invalid: {$url}", E_USER_WARNING);
                    return null;
                }
                return fopen($url, 'r', null, $context);
            },
            $request,
            $options
        );
    }
}
