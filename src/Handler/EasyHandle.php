<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

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
     * Attach a response to the easy handle based on the received headers.
     *
     * @throws \RuntimeException if no headers have been received or the first
     *                           header line is invalid.
     */
    public function createResponse(): void
    {
        $this->response = null;

        [$ver, $status, $reason, $headers] = HeaderProcessor::parseHeaders($this->headers);

        $normalizedKeys = Utils::normalizeHeaderKeys($headers);

        if (!empty($this->options['decode_content']) && isset($normalizedKeys['content-encoding'])) {
            $headers['x-encoded-content-encoding'] = $headers[$normalizedKeys['content-encoding']];
            unset($headers[$normalizedKeys['content-encoding']]);
            if (isset($normalizedKeys['content-length'])) {
                $headers['x-encoded-content-length'] = $headers[$normalizedKeys['content-length']];

                $bodyLength = (int) $this->sink->getSize();
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
