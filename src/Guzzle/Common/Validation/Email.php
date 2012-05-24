<?php

namespace Guzzle\Common\Validation;

/**
 * Ensures that a value is an email
 */
class Email implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        if (is_string($value)) {
            $value = (string) $value;
            $valid = filter_var($value, FILTER_VALIDATE_EMAIL);
            if ($valid) {
                return true;
            }
        }

        return 'Value is not a valid email address';
    }
}
