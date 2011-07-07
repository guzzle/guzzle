<?php

namespace Guzzle\Common\Filter;

use Guzzle\Common\Collection;
use Guzzle\Common\GuzzleException;

/**
 * An intercepting filter.
 *
 * @author  michael@guzzlephp.org
 */
abstract class AbstractFilter extends Collection implements FilterInterface
{
    /**
     * Create a new filter object.
     *
     * @param array|Collection $parameters (optional) Optional parameters to
     *      pass to the filter for processing.
     *
     * @throws GuzzleException if the $parameters argument is not an array or an
     *      instance of {@see Collection}
     */
    public function __construct($parameters = null)
    {
        if ($parameters instanceof Collection) {
            $this->data = $parameters->getAll();
        } else {
            parent::__construct($parameters);
        }

        $this->init();
    }

    /**
     * Process the command object.
     *
     * @param mixed $command Value to process.  The command can be any type of
     *      variable.  It is the responsibility of concrete filters to ensure
     *      that the passed command is of the correct type.
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function process($command)
    {
        $typeHint = $this->get('type_hint');
        if ($typeHint && !($command instanceof $typeHint)) {
            return false;
        }

        return $this->filterCommand($command);
    }

    /**
     * Filter the request and handle as needed.
     *
     * This method is a hook to be implemented in subclasses
     *
     * @param mixed $command The object to process
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    abstract protected function filterCommand($command);

    /**
     * Initialize the filter.
     *
     * This method is a hook to be implemented in subclasses that handles
     * initializing the filter.
     *
     * @return void
     */
    protected function init()
    {
        return;
    }
}