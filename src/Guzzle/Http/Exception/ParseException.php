<?php

namespace Guzzle\Http\Exception;

use Guzzle\Http\Message\ResponseInterface;

/**
 * Exception when a client is unable to parse the response body as XML or JSON
 */
class ParseException extends TransferException
{
    /** @var ResponseInterface */
    private $response;

    public function __construct(
        $message = '',
        ResponseInterface $response = null,
        \Exception $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->response = $response;
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
}
