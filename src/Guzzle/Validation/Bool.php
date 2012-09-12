<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is boolean ("true", "false", true, false, on, off, 1, 0)
 */
class Bool implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        return $value === true || $value === false || $value === 'true' ||
            $value === 'false' || $value === '1' || $value === '0' ||
            $value === 'on' || $value === 'off' || $value === 1 || $value === 0
            ? true
            : 'Value must be boolean';
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
