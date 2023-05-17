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
 * @property resource|\CurlHandle $handle resource cURL resource
 * @property StreamInterface $sink Where data is being written
 * @property array $headers Received HTTP headers so far
 * @property ResponseInterface|null $response Received response (if any)
 * @property RequestInterface $request Request being sent
 * @property array $options Request options
 * @property int $errno int cURL error number
 * @property \Throwable|null $onHeadersException Exception during on_headers (if any)
 * @property \Throwable|null $createResponseException Exception during createResponse (if any)
 *
 * @internal
 */
final class EasyHandle
{
    /**
     * @var resource|\CurlHandle cURL resource
     */
    private $handle;

    /**
     * @var StreamInterface Where data is being written
     */
    private $sink;

    /**
     * @var RequestInterface Request being sent
     */
    private $request;

    /**
     * @var array Request options
     */
    private $options = [];

    /**
     * @var int cURL error number (if any)
     */
    private $errno = \CURLE_OK;

    /**
     * @var array Received HTTP headers so far
     */
    private $headers = [];

    /**
     * @var ResponseInterface|null Received response (if any)
     */
    private $response;

    /**
     * @var \Throwable|null Exception during on_headers (if any)
     */
    private $onHeadersException;

    /**
     * @var \Throwable|null Exception during createResponse (if any)
     */
    private $createResponseException;

    /**
     * @var bool Tells if the EasyHandle has been initialized
     */
    private $initialized = false;

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
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function &__get(string $name)
    {
        if (('handle' !== $name && property_exists($this, $name)) || $this->initialized && isset($this->handle)) {
            return $this->{$name};
        }

        $msg = $name === 'handle'
            ? 'The EasyHandle ' . ($this->initialized ? 'has been released' : 'is not initialized')
            : sprintf('Undefined property: %s::$%s', __CLASS__, $name);

        throw new \BadMethodCallException($msg);
    }

    /**
     * @param mixed $value
     *
     * @throws \UnexpectedValueException|\LogicException
     */
    public function __set(string $name, $value): void
    {
        if ($this->initialized && !isset($this->handle)) {
            throw new \UnexpectedValueException('The EasyHandle has been released, please use a new EasyHandle instead.');
        }

        if (in_array($name, ['response', 'initialized'], true)) {
            throw new \LogicException(sprintf('Cannot set private property %s::$%s.', __CLASS__, $name));
        }

        if (in_array($name, ['errno', 'handle', 'headers', 'onHeadersException', 'createResponseException'], true)) {
            if ('handle' === $name) {
                if (isset($this->handle)) {
                    throw new \UnexpectedValueException(sprintf('Property %s::$%s is already set, please use a new EasyHandle instead.', __CLASS__, $name));
                }

                if (!is_resource($value) || 'curl' !== get_resource_type($value)) {
                    throw new \UnexpectedValueException(sprintf('Property %s::$%s can only accept a resource of type "curl".', __CLASS__, $name));
                }

                $this->initialized = true;
            } else {
                if (!isset($this->handle) || !is_resource($this->handle) || 'curl' !== get_resource_type($this->handle)) {
                    throw new \UnexpectedValueException(sprintf('Property %s::$%s could not be set when there isn\'t a valid handle.', __CLASS__, $name));
                }

                if ('errno' === $name && \CURLE_OK !== ($handleErrno = curl_errno($this->handle)) && $value !== $handleErrno) {
                    throw new \UnexpectedValueException(sprintf('Property %s::$errno could not be set with %u since the handle is reporting error %u.', __CLASS__, $value, $handleErrno));
                }
            }
        }

        $this->{$name} = $value;
    }

    /**
     * @throws \LogicException
     */
    public function __unset(string $name): void
    {
        if ('handle' !== $name) {
            throw new \Error(sprintf('Cannot unset private property %s::$%s.', __CLASS__, $name));
        }

        unset($this->{$name});
    }
}
