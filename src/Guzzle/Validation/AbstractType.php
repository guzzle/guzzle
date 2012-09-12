<?php

namespace Guzzle\Validation;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Ensures that a value is of a specific type
 */
abstract class AbstractType extends AbstractConstraint
{
    protected static $defaultOption = 'type';
    protected static $typeMapping = array();
    protected $required = 'type';

    /**
     * {@inheritdoc}
     */
    protected function validateValue($value, array $options = array())
    {
        $type = $options[self::$defaultOption];

        if (!isset(static::$typeMapping[$type])) {
            throw new InvalidArgumentException("{$type} is not one of the "
                . 'mapped types: ' . implode(', ', array_keys(static::$typeMapping)));
        }

        $method = static::$typeMapping[$type];
        if (!$method($value)) {
            return 'Value must be of type ' . $type;
        }

        return true;
    }
}
