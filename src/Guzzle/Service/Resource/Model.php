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
}
