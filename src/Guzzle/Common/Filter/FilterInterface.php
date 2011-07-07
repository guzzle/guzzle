<?php

namespace Guzzle\Common\Filter;

/**
 * An intercepting filter interface
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface FilterInterface
{
    /**
     * Process the command object.
     *
     * @param mixed $command Value to process.  The command can be any type of
     *      variable.  It is  the responsibility of concrete filters to ensure
     *      that the passed command is of the correct type.
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    function process($command);
}