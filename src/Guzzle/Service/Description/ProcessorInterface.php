<?php

namespace Guzzle\Service\Description;

/**
 * Processor responsible for preparing and validating parameters against the parameter's schema
 */
interface ProcessorInterface
{
    /**
     * Validate a value against the acceptable types, regular expressions, minimum, maximums, instance_of, enums, etc
     * Add default and static values to the passed in variable.
     * If the validation completes successfully, run the parameter through its filters.
     *
     * @param Parameter $param Parameter that is being validated against the value
     * @param mixed     $value Value to validate and process. The value may change during this process.
     *
     * @return bool|array Returns true if valid, or an array of error messages if invalid
     */
    public function process(Parameter $param, &$value);
}
