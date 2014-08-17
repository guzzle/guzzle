<?php

namespace GuzzleHttp\Exception;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * HTTP Request exception
 */
class RequestException extends TransferException
{
    /** @var bool */
    private $emittedErrorEvent = false;

    /** @var RequestInterface */
    private $request;

    /** @var ResponseInterface */
    private $response;

    /** @var bool */
    private $throwImmediately = false;

    public function __construct(
        $message = '',
        RequestInterface $request,
        ResponseInterface $response = null,
        \Exception $previous = null
    ) {
        $code = $response ? $response->getStatusCode() : 0;
        parent::__construct($message, $code, $previous);
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Factory method to create a new exception with a normalized error message
     *
     * @param RequestInterface  $request  Request
     * @param ResponseInterface $response Response received
     * @param \Exception        $previous Previous exception
     *
     * @return self
     */
    public static function create(
        RequestInterface $request,
        ResponseInterface $response = null,
        \Exception $previous = null
    ) {
        if (!$response) {
            return new self('Error completing request', $request, null, $previous);
        }

        $level = $response->getStatusCode()[0];
        if ($level == '4') {
            $label = 'Client error response';
            $className = __NAMESPACE__ . '\\ClientException';
        } elseif ($level == '5') {
            $label = 'Server error response';
            $className = __NAMESPACE__ . '\\ServerException';
        } else {
            $label = 'Unsuccessful response';
            $className = __CLASS__;
        }

        $message = $label . ' [url] ' . $request->getUrl()
            . ' [status code] ' . $response->getStatusCode()
            . ' [reason phrase] ' . $response->getReasonPhrase();

        return new $className($message, $request, $response, $previous);
    }

    /**
     * Get the request that caused the exception
     *
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get the associated response
     *
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Check if a response was received
     *
     * @return bool
     */
    public function hasResponse()
    {
        return $this->response !== null;
    }

    /**
     * Check or set if the exception was emitted in an error event.
     *
     * This value is used in the RequestEvents::emitBefore() method to check
     * to see if an exception has already been emitted in an error event.
     *
     * @param bool|null Set to true to set the exception as having emitted an
     *     error. Leave null to retrieve the current setting.
     *
     * @return null|bool
     * @throws \InvalidArgumentException if you attempt to set the value to false
     */
    public function emittedError($value = null)
    {
        if ($value === null) {
            return $this->emittedErrorEvent;
        } elseif ($value === true) {
            $this->emittedErrorEvent = true;
        } else {
            throw new \InvalidArgumentException('You cannot set the emitted '
                . 'error value to false.');
        }
    }

    /**
     * Sets whether or not parallel adapters SHOULD throw the exception
     * immediately rather than handling errors through asynchronous error
     * handling.
     *
     * @param bool $throwImmediately
     *
     */
    public function setThrowImmediately($throwImmediately)
    {
        $this->throwImmediately = $throwImmediately;
    }

    /**
     * Gets the setting specified by setThrowImmediately().
     *
     * @return bool
     */
    public function getThrowImmediately()
    {
        return $this->throwImmediately;
    }
}
