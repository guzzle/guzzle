<?php

namespace Guzzle\Service\Resource;

use Guzzle\Common\Collection;
use Guzzle\Service\Description\Parameter;

/**
 * Default model created when commands create service description model responses
 */
class Model extends Collection
{
    /**
     * @var Parameter Structure of the model
     */
    protected $structure;

    /**
     * @param array     $data      Data contained by the model
     * @param Parameter $structure The structure of the model
     */
    public function __construct(array $data = array(), Parameter $structure = null)
    {
        $this->data = $data;
        $this->structure = $structure ?: new Parameter();
    }

    /**
     * Get the structure of the model
     *
     * @return Parameter
     */
    public function getStructure()
    {
        return $this->structure;
    }

    /**
     * Gets a value from the model using an array path (e.g. foo/baz/bar would retrieve bar from two nested arrays)
     *
     * @param string $path      Path to traverse and retrieve a value from
     * @param string $separator Character used to add depth to the search
     *
     * @return mixed|null
     */
    public function getPath($path, $separator = '/')
    {
        $parts = explode($separator, $path);
        $data = &$this->data;

        // Using an iterative approach rather than recursion for speed
        while (null !== ($part = array_shift($parts))) {
            // Return null if this path doesn't exist or if there's more depth and the value is not an array
            if (!isset($data[$part]) || ($parts && !is_array($data[$part]))) {
                return null;
            }
            $data = &$data[$part];
        }

        return $data;
    }
}
