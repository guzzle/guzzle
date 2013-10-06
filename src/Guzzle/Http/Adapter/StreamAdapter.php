<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Event\GotResponseHeadersEvent;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Stream\Stream;

/**
 * HTTP adapter that uses PHP's HTTP stream wrapper
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
        try {
            $this->createResponse($transaction);
            $transaction->getRequest()->getEventDispatcher()->dispatch(
                'request.after_send',
                new RequestAfterSendEvent($transaction)
            );
        } catch (RequestException $e) {
            if (!$transaction->getRequest()->getEventDispatcher()->dispatch(
                'request.error',
                new RequestErrorEvent($transaction, $e)
            )->isPropagationStopped()) {
                throw $e;
            }
        }
    }

    /**
     * @param TransactionInterface
     * @throws \LogicException if you attempt to stream and specify a write_to option
     */
    private function createResponse(TransactionInterface $transaction)
    {
        $request = $transaction->getRequest();
        $stream = $this->createStream($request, $http_response_header);

        if (!$request->getConfig()['stream']) {
            $stream = $this->getSaveToBody($request, $stream);
        }

        // Track the response headers of the request
        $this->createResponseObject($http_response_header, $transaction, $stream);
    }

    /**
     * Drain the steam into the destination stream
     */
    private function getSaveToBody(RequestInterface $request, $stream)
    {
        if ($saveTo = $request->getConfig()['save_to']) {
            // Stream the response into the destination stream
            $saveTo = is_string($saveTo)
                ? Stream::factory(fopen($saveTo, 'r+'))
                : Stream::factory($saveTo);
        } else {
            // Stream into the default temp stream
            $saveTo = Stream::factory();
        }

        while (!feof($stream)) {
            $saveTo->write(fread($stream, 8096));
        }

        fclose($stream);
        $saveTo->seek(0);

        return $saveTo;
    }

    private function createResponseObject($headers, TransactionInterface $transaction, $stream)
    {
        $parts = explode(' ', array_shift($headers), 3);
        $options = ['protocol_version' => substr($parts[0], -3)];
        if (isset($parts[2])) {
            $options['reason_phrase'] = $parts[2];
        }

        // Set the size on the stream if it was returned in the response
        $responseHeaders = [];
        foreach ($headers as $header) {
            $headerParts = explode(':', $header, 2);
            $responseHeaders[$headerParts[0]] = isset($headerParts[1]) ? $headerParts[1] : '';
        }

        $response = $this->messageFactory->createResponse($parts[1], $responseHeaders, $stream, $options);
        $transaction->setResponse($response);

        $transaction->getRequest()->getEventDispatcher()->dispatch(
            RequestEvents::RESPONSE_HEADERS,
            new GotResponseHeadersEvent($transaction)
        );

        return $response;
    }

    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable         $callback Callable to invoke that must return a valid resource
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

        // If the resource could not be created, then grab the last error and throw an exception
        if (false === $resource) {
            $message = 'Error creating resource. [url] ' . $request->getUrl() . ' ';
            if (isset($options['http']['proxy'])) {
                $message .= "[proxy] {$options['http']['proxy']} ";
            }
            foreach (error_get_last() as $key => $value) {
                $message .= "[{$key}] {$value} ";
            }

            throw new RequestException(trim($message), $request);
        }

        return $resource;
    }

    /**
     * Create the stream for the request with the context options.
     *
     * Stream context parameters may be set in the '_params' option key.
     *
     * @param RequestInterface $request              Request being sent
     * @param mixed            $http_response_header Value is populated by stream wrapper
     *
     * @return resource
     */
    private function createStream(RequestInterface $request, &$http_response_header)
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        $options = ['http' => [
            'method' => $request->getMethod(),
            'header' => (string) $request->getHeaders(),
            'protocol_version' => '1.0',
            'ignore_errors' => true,
            'follow_location' => 0,
            'content' => (string) $request->getBody()
        ]];

        foreach ($request->getConfig()->toArray() as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $options, $value);
            }
        }

        $params = null;
        if (isset($options['_params'])) {
            $params = $options['_params'];
            unset($options['_params']);
        }

        $context = $this->createResource(function () use ($request, $options, $params) {
            $context = stream_context_create($options);
            if ($params) {
                stream_context_set_params($context, $params);
            }
            return $context;
        }, $request, $options);

        $url = $request->getUrl();
        // Add automatic gzip decompression
        if (strpos($request->getHeader('Accept-Encoding'), 'gzip') !== false) {
            $url = 'compress.zlib://' . $url;
        }

        return $this->createResource(function () use ($url, &$http_response_header, $context) {
            return fopen($url, 'r', null, $context);
        }, $request, $options);
    }

    private function visit_proxy(RequestInterface $request, &$options, $value)
    {
        $options['http']['proxy'] = $value;
    }

    private function visit_timeout(RequestInterface $request, &$options, $value)
    {
        $options['http']['timeout'] = $value;
    }

    private function visit_verify(RequestInterface $request, &$options, $value)
    {
        if ($value === true || is_string($value)) {
            $options['http']['verify_peer'] = true;
            if ($value !== true) {
                if (!file_exists($value)) {
                    throw new \RuntimeException('SSL Certificate file not found: ' . $value);
                }
                $options['http']['allow_self_signed'] = true;
                $options['http']['cafile'] = $value;
            }
        } elseif ($value === false) {
            $options['http']['verify_peer'] = false;
        }
    }

    private function visit_cert(RequestInterface $request, &$options, $value)
    {
        if (is_array($value)) {
            $options['http']['local_cert'] = $value[0];
            $options['http']['passphrase'] = $value[1];
        } else {
            $options['http']['local_cert'] = $value;
        }
    }

    private function visit_debug(RequestInterface $request, &$options, $value)
    {
        if (!is_resource($value)) {
            $value = fopen('php://output', 'w');
        }

        $options['_params'] = array(
            'notification' => function (
                $code,
                $severity,
                $message,
                $message_code,
                $bytes_transferred,
                $bytes_max
            ) use ($request, $value) {
                fwrite($value, '<' . $request->getUrl() . '>: ');
                switch ($code) {
                    case STREAM_NOTIFY_COMPLETED:
                        fwrite($value, 'Completed request to ' . $request->getUrl() . "\n");
                        break;
                    case STREAM_NOTIFY_FAILURE:
                        fwrite($value, "Failure: {$message_code} {$message} \n");
                        break;
                    case STREAM_NOTIFY_RESOLVE:
                    case STREAM_NOTIFY_AUTH_REQUIRED:
                    case STREAM_NOTIFY_AUTH_RESULT:
                        var_dump($code, $severity, $message, $message_code, $bytes_transferred, $bytes_max);
                        break;
                    case STREAM_NOTIFY_CONNECT:
                        fputs($value, "Connected...\n");
                        break;
                    case STREAM_NOTIFY_FILE_SIZE_IS:
                        fputs($value, "Got the filesize: {$bytes_max}\n");
                        break;
                    case STREAM_NOTIFY_MIME_TYPE_IS:
                        fputs($value, "Found the mime-type: {$message}\n");
                        break;
                    case STREAM_NOTIFY_PROGRESS:
                        fputs($value, "Downloaded {$bytes_transferred} bytes\n");
                        break;
                }
            }
        );
    }
}
