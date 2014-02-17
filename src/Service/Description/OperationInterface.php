<?php

namespace GuzzleHttp\Service\Description;

/**
 * Interface defining data objects that hold the information of an API operation
 */
interface OperationInterface extends NodeInterface
{
    /**
     * Get the input data of the operation
     *
     * @return array
     */
    public function getInput();

    /**
     * Get the output data of the operation
     *
     * @return array
     */
    public function getOutput();

    /**
     * Get the error data of the operation
     *
     * @return array
     */
    public function getErrors();

    /**
     * Get the HTTP method of the operation
     *
     * @return string|null
     */
    public function getHttpMethod();

    /**
     * Get the URI that will be merged into the generated request
     *
     * @return string
     */
    public function getUri();

    /**
     * Get a short summary of what the operation does
     *
     * @return string|null
     */
    public function getSummary();

    /**
     * Get the documentation URL of the operation
     *
     * @return string|null
     */
    public function getDocumentationUrl();

    /**
     * Get notes about the response of the operation
     *
     * @return string|null
     */
    public function getResponseNotes();

    /**
     * Get whether or not the operation is deprecated
     *
     * @return bool
     */
    public function isDeprecated();
}
