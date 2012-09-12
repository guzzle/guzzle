<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is an IP address
 */
class Ip implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        return filter_var($value, FILTER_VALIDATE_IP) ? true : 'Value is not a valid IP address';
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
