<?php

namespace GuzzleHttp\Service\Description;

/**
 * A ServiceDescription stores information about a web service.
 */
interface DescriptionInterface
{
    /**
     * Get metadata from the description.
     *
     * @param string $key Data to retrieve
     *
     * @return null|mixed Returns the value or null if the value does not exist
     */
    public function getMetadata($key);

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

    /**
     * Get a shared definition structure.
     *
     * @param string $name Name of the definition to retrieve
     *
     * @return NodeInterface
     */
    public function getDefinition($name);

    /**
     * Check if the service description has a definition by name.
     *
     * @param string $name Name of the definition to check
     *
     * @return bool
     */
    public function hasDefinition($name);
}
