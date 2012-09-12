<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is blank
 */
class Blank implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        return $value !== '' && $value !== null ? 'Value must be blank' : true;
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
