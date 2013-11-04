<?php

namespace Guzzle\Service;

use Guzzle\Common\ToArrayInterface;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * A command object manages input and output of an operation using an
 * {@see OperationInterface} object.
 */
interface CommandInterface extends \ArrayAccess, ToArrayInterface
{
    /**
     * Get the API operation information about the command
     *
     * @return OperationInterface
     */
    public function getOperation();

    /**
     * Get a request object for the command, that is validated, serialized,
     * and ready to send.
     *
     * @return RequestInterface
     */
    public function getRequest();

    /**
     * Validate and parse a Response into an appropriate result for the
     * operation.
     *
     * @param ResponseInterface $response Response to parse
     *
     * @return ModelInterface|mixed
     */
    public function processResponse(ResponseInterface $response);

    /**
     * Parse a RequestException into an appropriate result
     *
     * @param RequestException $e Exception to parse
     *
     * @return ModelInterface|mixed
     */
    public function processError(RequestException $e);
}
