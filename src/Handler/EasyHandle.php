<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Represents a cURL easy handle and the data it populates.
 *
 * @internal
 */
final class EasyHandle
{
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

        [$ver, $status, $reason, $headers] = HeaderProcessor::parseHeaders($this->headers);

        // Non-101 informational responses precede the final response. Do not
        // expose them as the response for a transfer that ends before the final
        // response arrives. 101 switches protocol and is kept as terminal.
        if ($status < 200 && $status !== 101) {
            return;
        }

        $normalizedKeys = Utils::normalizeHeaderKeys($headers);

        if (!empty($this->options['decode_content']) && isset($normalizedKeys['content-encoding'])) {
            $headers['x-encoded-content-encoding'] = $headers[$normalizedKeys['content-encoding']];
            unset($headers[$normalizedKeys['content-encoding']]);
            if (isset($normalizedKeys['content-length'])) {
                $headers['x-encoded-content-length'] = $headers[$normalizedKeys['content-length']];

                try {
                    $bodyLength = $this->sink->getSize();
                } catch (\Exception $e) {
                    $bodyLength = null;
                }
                if ($bodyLength) {
                    $headers[$normalizedKeys['content-length']] = [(string) $bodyLength];
                } else {
                    unset($headers[$normalizedKeys['content-length']]);
                }
            }
        }

        // Attach a response to the easy handle with the parsed headers.
        $this->response = new Response(
            $status,
            $headers,
            $this->sink,
            $ver,
            $reason
        );
    }

    /**
     * @throws \BadMethodCallException
     */
    public function __get(string $name): void
    {
        $msg = $name === 'handle' ? 'The EasyHandle has been released' : 'Invalid property: '.$name;
        throw new \BadMethodCallException($msg);
    }
}
