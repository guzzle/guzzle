<?php

namespace Guzzle\Common\Validation;

/**
 * Ensures that a value is a string
 */
class String implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        if (!is_string($value)) {
            return 'Value must be a string';
        }

        return true;
    }
}
