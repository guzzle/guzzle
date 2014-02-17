<?php

namespace GuzzleHttp\Exception;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * HTTP Request exception
 */
class RequestException extends TransferException
{
    /** @var RequestInterface */
    private $request;

    /** @var ResponseInterface */
    private $response;

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
