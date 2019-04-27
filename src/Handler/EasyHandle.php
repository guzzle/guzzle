<?php

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

    /**
     * @var StreamInterface Where data is being written
     */
    public $sink;

    /**
     * @var RequestInterface Request being sent
     */
    public $request;

    /**
     * @var array Request options
     */
    public $options = [];

    /**
     * @var \Throwable|null Exception during on_headers (if any)
     */
    public $onHeadersException;

    /**
     * @var \Exception|null Exception during createResponse (if any)
     */
    public $createResponseException;

    /**
     * @var int cURL error number (if any)
     */
    private $errno = CURLE_OK;

    /**
     * @var array Received HTTP headers so far
     */
    private $headers = [];

    /**
     * @var ResponseInterface|null Received response (if any)
     */
    private $response;

    /**
     * Attach a response to the easy handle based on the received headers.
     *
     * @throws \RuntimeException if no headers have been received or the first
     *                           header line is invalid.
     */
    public function createResponse(): void
    {
        [$ver, $status, $reason, $headers] = HeaderProcessor::parseHeaders($this->headers);

        $normalizedKeys = Utils::normalizeHeaderKeys($headers);

        if (!empty($this->options['decode_content']) && isset($normalizedKeys['content-encoding'])) {
            $headers['x-encoded-content-encoding'] = $headers[$normalizedKeys['content-encoding']];
            unset($headers[$normalizedKeys['content-encoding']]);
            if (isset($normalizedKeys['content-length'])) {
                $headers['x-encoded-content-length'] = $headers[$normalizedKeys['content-length']];

                $bodyLength = (int) $this->sink->getSize();
                if ($bodyLength) {
                    $headers[$normalizedKeys['content-length']] = $bodyLength;
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
     * @param string $name
     *
     * @return void
     *
     * @throws \BadMethodCallException
     */
    public function &__get($name)
    {
        if (in_array($name, ['errno', 'headers', 'onHeadersException', 'response'], true)) {
            return $this->{$name};
        }

        $msg = $name === 'handle'
            ? 'The EasyHandle has been released'
            : sprintf('Undefined property: %s::$%s', __CLASS__, $name);

        throw new \BadMethodCallException($msg);
    }

    public function __set($name, $value)
    {
        if (in_array($name, ['errno', 'headers', 'onHeadersException', 'response'], true)) {
            if ('response' === $name) {
                // BC: Change to `\Error` when bumping PHP version to ^7.0
                throw new \LogicException(sprintf('Cannot set private property %s::$%s', __CLASS__, $name));
            }

            if (!isset($this->handle) || !is_resource($this->handle) || 'curl' !== get_resource_type($this->handle)) {
                throw new \LogicException(sprintf('Property %s::$%s could not be set when there isn\'t a valid handle', __CLASS__, $name));
            }

            if ('errno' === $name && CURLE_OK !== ($handleErrno = curl_errno($this->handle)) && $value !== $handleErrno) {
                throw new \LogicException(sprintf('Property %s::$errno could not be set with %u since the handle is reporting error %u', __CLASS__, $value, $handleErrno));
            }
        }

        $this->{$name} = $value;
    }
}
