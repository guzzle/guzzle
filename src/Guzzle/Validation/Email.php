<?php

namespace Guzzle\Validation;

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
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return true;
            }
        }

        return 'Value is not a valid email address';
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public static function getDefaultOption()
    {
        return null;
    }
}
