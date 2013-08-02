<?php

namespace Guzzle\Http\Exception;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * HTTP Request exception
 */
class RequestException extends TransferException
{
    /** @var RequestInterface */
    protected $request;

    /** @var ResponseInterface */
    protected $response;

    public function __construct(
        $message = '',
        RequestInterface $request,
        ResponseInterface $response = null,
        \Exception $previous = null
    ) {
        parent::__construct($message, 0, $previous);
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
            $label = 'Error completing request';
        } elseif ($response->isClientError()) {
            $label = 'Client error response';
        } elseif ($response->isServerError()) {
            $label = 'Server error response';
        } else {
            $label = 'Unsuccessful response';
        }

        $message = $label . ' [url] ' . $request->getUrl()
            . ' [status code] ' . $response->getStatusCode()
            . ' [reason phrase] ' . $response->getReasonPhrase();

        return new self($message, $request, $response, $previous);
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
     * Get the associated repsonse
     *
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Check if a response was recieved
     *
     * @return bool
     */
    public function hasResponse()
    {
        return $this->response !== null;
    }
}
