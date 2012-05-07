<?php

namespace Guzzle\Common\Validation;

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
        $valid = filter_var($value, FILTER_VALIDATE_IP);

        if (!$valid) {
            return 'Value is not a valid IP address';
        }

        return true;
    }
}
