<?php

namespace Guzzle\Service;

use Guzzle\Common\ToArrayInterface;

/**
 * Represents a node in which all description properties implement
 */
interface NodeInterface extends ToArrayInterface
{
    /**
     * Get the name of the node
     *
     * @return string|null
     */
    public function getName();

    /**
     * Get a description of the node
     *
     * @return string|null
     */
    public function getDescription();

    /**
     * Get extra data from the node
     *
     * @param string $name Name of the data point to retrieve or null to get all
     *
     * @return array|mixed|null
     */
    public function getMetadata($name = null);

    /**
     * Get all of the members of the node
     *
     * @return array
     */
    public function getMembers();
}
