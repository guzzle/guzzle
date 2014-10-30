<?php
namespace GuzzleHttp\Exception;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Ring\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectException as HttpConnectException;
use GuzzleHttp\Ring\Future\FutureInterface;

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
        $message,
        RequestInterface $request,
        ResponseInterface $response = null,
        \Exception $previous = null
    ) {
        // Set the code of the exception if the response is set and not future.
        $code = $response && !($response instanceof FutureInterface)
            ? $response->getStatusCode()
            : 0;
        parent::__construct($message, $code, $previous);
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Wrap non-RequesExceptions with a RequestException
     *
     * @param RequestInterface $request
     * @param \Exception       $e
     *
     * @return RequestException
     */
    public static function wrapException(RequestInterface $request, \Exception $e)
    {
        if ($e instanceof RequestException) {
            return $e;
        } elseif ($e instanceof ConnectException) {
            return new HttpConnectException($e->getMessage(), $request, null, $e);
        } else {
            return new RequestException($e->getMessage(), $request, null, $e);
        }
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

        $level = floor($response->getStatusCode() / 100);
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
}
