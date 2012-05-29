<?php

namespace Guzzle\Common\Validation;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Service\Inspector;

/**
 * Ensures that a value passes any of the validation constraints.
 *
 * A 'constraints' and 'inspector' option are required. Filters are separated
 * by semicolons, and passed to an inspector.
 */
class AnyMatch extends AbstractConstraint
{
    protected $default = 'constraints';
    protected $required = 'constraints';

    /**
     * {@inheritdoc}
     */
    protected function validateValue($value, array $options = array())
    {
        if (!isset($options['inspector'])) {
            $options['inspector'] = Inspector::getInstance();
        }

        $inspector = $options['inspector'];
        foreach (explode(';', $options['constraints']) as $constraint) {
            if (true === $inspector->validateConstraint(trim($constraint), $value)) {
                return true;
            }
        }

        return 'Value type must match one of ' . implode(' OR ' , explode(';', $options['constraints']));
    }
}
