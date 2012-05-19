<?php

namespace Guzzle\Common\Validation;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Abstract constraint class
 */
abstract class AbstractConstraint implements ConstraintInterface
{
    protected $default;
    protected $required;

    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        // Always pass an array to the hook method
        if (!$options) {
            $options = array();
        } elseif ($this->default && isset($options[0])) {
            // Add the default configuration option if an enumerated array
            // is passed
            $options[$this->default] = $options[0];
        }

        // Ensure that required options are present
        if ($this->required && !isset($options[$this->required])) {
            throw new InvalidArgumentException("{$this->required} is a required validation option");
        }

        return $this->validateValue($value, $options);
    }

    /**
     * Perform the actual validation in a concrete class
     *
     * @param mixed $value   Value to validate
     * @param array $options Validation options
     *
     * @return bool|string
     */
    abstract protected function validateValue($value, array $options = array());
}
