<?php

namespace GuzzleHttp\Service\Description;

/**
 * A ServiceDescription stores service information based on a service document
 */
interface DescriptionInterface extends NodeInterface
{
    /**
     * Get the basePath/baseUrl of the description
     *
     * @return string
     */
    public function getBaseUrl();

    /**
     * Get the API version of the service
     *
     * @return string
     */
    public function getApiVersion();

    /**
     * Set arbitrary data on the service description
     *
     * @param string $key   Data key to set
     * @param mixed  $value Value to set
     *
     * @return self
     */
    public function setMetadata($key, $value);

    /**
     * Get the API operations of the service
     *
     * @return array Returns an array of {@see OperationInterface} objects
     */
    public function getOperations();

    /**
     * Check if the service has an operation by name
     *
     * @param string $name Name of the operation to check
     *
     * @return bool
     */
    public function hasOperation($name);

    /**
     * Get an API operation by name
     *
     * @param string $name Name of the command
     *
     * @return OperationInterface
     * @throws \InvalidArgumentException if the operation is not found
     */
    public function getOperation($name);
}
