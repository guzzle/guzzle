<?php

namespace Guzzle\Common\Validation;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Ensures that a value is of a specific type
 */
abstract class AbstractType extends AbstractConstraint
{
    protected static $typeMapping = array();
    protected $default = 'type';
    protected $required = 'type';

    /**
     * {@inheritdoc}
     */
    protected function validateValue($value, array $options = array())
    {
        $type = $options['type'];

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
