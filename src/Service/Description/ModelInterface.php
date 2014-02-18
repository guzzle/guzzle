<?php

namespace GuzzleHttp\Service\Description;

use GuzzleHttp\ToArrayInterface;

/**
 * Represents a response model that is returned when executing a web service
 * operation.
 */
interface ModelInterface extends \ArrayAccess, \Traversable, ToArrayInterface
{
    /**
     * Get an element from the model using path notation.
     *
     * @param string $path Path to the data to retrieve
     *
     * @return mixed|null Returns the result or null if the path is not found
     */
    public function getPath($path);
};
