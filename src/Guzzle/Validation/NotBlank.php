<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is not blank
 */
class NotBlank implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        return $value === false || (empty($value) && $value !== '0') ? 'Value must not be blank' : true;
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
