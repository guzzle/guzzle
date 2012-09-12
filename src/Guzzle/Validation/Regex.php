<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value matches a regexp
 */
class Regex extends AbstractConstraint
{
    protected static $defaultOption = 'pattern';
    protected $required = 'pattern';

    /**
     * {@inheritdoc}
     */
    public function validateValue($value, array $options = null)
    {
        return preg_match($options[self::$defaultOption], $value)
            ? true
            : "{$value} does not match the regular expression";
    }
}
