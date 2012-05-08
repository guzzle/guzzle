<?php

namespace Guzzle\Common\Validation;

/**
 * Ensures that a value is a valid URL
 */
class Url implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        $value = (string) $value;
        $valid = filter_var($value, FILTER_VALIDATE_URL);

        if (!$valid) {
            return "{$value} is not a valid URL";
        }

        return true;
    }
}
