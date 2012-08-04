<?php

namespace Guzzle\Common\Validation;

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

            $constraint = trim($constraint);

            // Handle colon separated values
            if (strpos($constraint, ':')) {
                list($constraint, $args) = explode(':', $constraint, 2);
                $args = strpos($args, ',') !== false ? str_getcsv($args, ',', "'") : array($args);
            } else {
                $args = null;
            }

            if (true === $inspector->validateConstraint($constraint, $value, $args)) {
                return true;
            }
        }

        return 'Value type must match one of ' . implode(' OR ' , explode(';', $options['constraints']));
    }
}
