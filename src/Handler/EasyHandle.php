<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\DiagnosticValue;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Represents a cURL easy handle and the data it populates.
 *
 * @internal
 */
final class EasyHandle
{
    use NonSerializableTrait;

    /**
     * @var resource|\CurlHandle cURL resource
     */
    public $handle;

    public StreamInterface $sink;

    public RequestInterface $request;

    /**
     * @var list<string> Received HTTP headers so far
     */
    public array $headers = [];

    /**
     * @var list<string> Valid trailer lines, retained only when an
     *                   on_trailers callback is configured
     */
    public array $trailers = [];

    /**
     * @var bool Whether this handle was configured with CURLOPT_PIPEWAIT
     */
    public bool $usesPipewait = false;

    /**
     * @var ResponseInterface|null Received response (if any)
     */
    public ?ResponseInterface $response = null;

    /**
     * @var array Request options
     */
    public array $options = [];

    /**
     * @var int cURL error number (if any)
     */
    public int $errno = 0;

    /**
     * @var string|null Effective CURLOPT_PROXY value the handle was created with (if any)
     */
    public ?string $effectiveProxy = null;

    /**
     * Proxy tunnel or SOCKS proxy section signature for connection-reuse
     * isolation, or null when the request does not require sectioning.
     */
    public ?string $proxyTunnelSignature = null;

    /**
     * @var \Throwable|null Exception during on_headers (if any)
     */
    public ?\Throwable $onHeadersException = null;

    /**
     * @var \Throwable|null Exception during progress callback (if any)
     */
    public ?\Throwable $progressException = null;

    /**
     * @var bool Whether the progress callback requested abort
     */
    public bool $progressAborted = false;

    /**
     * @var \Throwable|null Exception during createResponse (if any)
     */
    public ?\Throwable $createResponseException = null;

    /**
     * @var ResponseException|null Response header failure, if any.
     */
    public ?ResponseException $responseHeaderException = null;

    /**
     * @var TimeoutException|null Exception during request body read timeout.
     */
    public ?TimeoutException $bodyReadTimeoutException = null;

    /**
     * @var \Throwable|null Exception during request body read.
     */
    public ?\Throwable $bodyReadException = null;

    /**
     * @var TimeoutException|null Exception during response sink write timeout.
     */
    public ?TimeoutException $sinkWriteTimeoutException = null;

    /**
     * @var \Throwable|null Exception during response sink write.
     */
    public ?\Throwable $sinkWriteException = null;

    /**
     * @var bool Whether the response sink accepted a different byte count.
     */
    public bool $sinkWriteIncomplete = false;

    /**
     * @var int Number of response body bytes accepted by the sink.
     */
    public int $responseBodyBytes = 0;

    /**
     * @var \OverflowException|null Unrepresentable response body size or byte count.
     */
    public ?\OverflowException $responseBodySizeException = null;

    /**
     * Attach a response to the easy handle based on the received headers.
     *
     * @throws \RuntimeException if no headers have been received or the first
     *                           header line is invalid.
     */
    public function createResponse(): void
    {
        $this->response = null;
        $this->responseBodyBytes = 0;
        $this->responseBodySizeException = null;
        $this->responseHeaderException = null;

        [$ver, $status, $reason, $headers] = HeaderProcessor::parseHeaders($this->headers);

        // Non-101 informational responses precede the final response. Do not
        // expose them as the response for a transfer that ends before the final
        // response arrives. 101 switches protocol and is kept as terminal.
        if ($status < 200 && $status !== 101) {
            return;
        }

        $framingFailure = null;
        try {
            $declaredLength = HeaderProcessor::validateResponseFraming($this->request->getMethod(), $status, $headers);
            HeaderProcessor::assertContentLengthWithinPlatformLimit($declaredLength);
        } catch (\RuntimeException $e) {
            $framingFailure = $e;
        }

        $normalizedKeys = Utils::normalizeHeaderKeys($headers);
        $decodeContent = $this->options['decode_content'] ?? false;
        if ($framingFailure === null && $decodeContent !== false && isset($normalizedKeys['content-encoding'])) {
            $headers['x-encoded-content-encoding'] = $headers[$normalizedKeys['content-encoding']];
            unset($headers[$normalizedKeys['content-encoding']]);
            $encodedContentLength = HeaderProcessor::removeHeader('Content-Length', $headers);
            if ($encodedContentLength !== []) {
                $headers['x-encoded-content-length'] = $encodedContentLength;

                try {
                    $bodyLength = $this->sink->getSize();
                } catch (\Exception $e) {
                    $bodyLength = null;
                }
                if ($bodyLength) {
                    $headers['Content-Length'] = [(string) $bodyLength];
                }
            }
        }

        // Attach a response to the easy handle with the parsed headers. Any
        // exception propagates to the caller (CurlFactory), which records it as
        // the createResponseException — do not catch it here.
        $responseFactory = self::requireResponseFactory($this->options[RequestOptions::RESPONSE_FACTORY] ?? new HttpFactory());
        $response = $responseFactory->createResponse($status, $reason ?? '')->withProtocolVersion($ver);
        foreach ($headers as $name => $value) {
            $response = $response->withAddedHeader((string) $name, $value);
        }
        $this->response = $response->withBody($this->sink);

        if ($framingFailure instanceof \OverflowException) {
            $this->responseHeaderException = new ResponseException(
                $framingFailure->getMessage(),
                $this->request,
                $this->response,
                $framingFailure
            );
        } elseif ($framingFailure !== null) {
            $this->responseHeaderException = new ResponseTransferException(
                $framingFailure->getMessage(),
                $this->request,
                $this->response,
                $framingFailure
            );
        }
    }

    /**
     * @param mixed $factory
     */
    private static function requireResponseFactory($factory): ResponseFactoryInterface
    {
        if (!$factory instanceof ResponseFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::RESPONSE_FACTORY,
                ResponseFactoryInterface::class
            ));
        }

        return $factory;
    }

    /**
     * @throws \BadMethodCallException
     */
    public function __get(string $name): void
    {
        $msg = $name === 'handle'
            ? 'The EasyHandle has been released'
            : \sprintf('Invalid property: %s', DiagnosticValue::escape($name));

        throw new \BadMethodCallException($msg);
    }
}
