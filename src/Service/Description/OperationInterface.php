<?php

namespace GuzzleHttp\Service\Description;

/**
 * Interface defining API operations.
 */
interface OperationInterface extends NodeInterface
{
    /**
     * Get the input data of the operation
     *
     * @return NodeInterface
     */
    public function getInput();

    /**
     * Get the output data of the operation
     *
     * @return NodeInterface
     */
    public function getOutput();

    /**
     * Get the error data of the operation
     *
     * @return array
     */
    public function getErrors();

    /**
     * Get the HTTP trait of the operation.
     *
     * @return array Returns an associative array containing the following keys
     *     - "method": The HTTP method to send
     *     - "requestUri": The URI of the request to send
     */
    public function getHttp();

    /**
     * Get whether or not the operation is deprecated
     *
     * @return bool
     */
    public function isDeprecated();
}
