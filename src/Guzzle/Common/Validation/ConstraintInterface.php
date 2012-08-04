<?php

namespace Guzzle\Common\Validation;

/**
 * Interface for validating values
 */
interface ConstraintInterface
{
    /**
     * Checks if the passed value is valid.
     *
     * @param mixed $value   The value to validate
     * @param array $options Constraint options
     *
     * @return bool|string Returns TRUE if valid, or an error message string if the value is not valid.
     */
    public function validate($value, array $options = null);
}
