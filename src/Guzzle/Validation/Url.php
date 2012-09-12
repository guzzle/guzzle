<?php

namespace Guzzle\Validation;

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
        return filter_var((string) $value, FILTER_VALIDATE_URL) ? true : "Value is not a valid URL";
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
