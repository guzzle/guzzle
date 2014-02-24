<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\ToArrayInterface;

/**
 * Represents a response model that is returned when executing a web service
 * operation.
 */
interface ModelInterface extends \ArrayAccess, \IteratorAggregate, ToArrayInterface
{
    /**
     * Get an element from the model using path notation.
     *
     * @param string $path Path to the data to retrieve
     *
     * @return mixed|null Returns the result or null if the path is not found
     */
    public function getPath($path);

    /**
     * Check if the model contains a key by name
     *
     * @param string $name Name of the key to retrieve
     *
     * @return bool
     */
    public function hasKey($name);
};
