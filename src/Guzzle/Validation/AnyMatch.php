<?php

namespace Guzzle\Validation;

use Guzzle\Service\Inspector;

/**
 * Ensures that a value passes any of the validation constraints.
 *
 * A 'constraints' and 'inspector' option are required. Filters are separated by semicolons, and passed to an inspector.
 */
class AnyMatch extends AbstractConstraint
{
    protected static $defaultOption = 'constraints';
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
        foreach ($options[self::$defaultOption] as $constraint) {

            if (is_string($constraint)) {
                if (strpos($constraint, ':')) {
                    list($type, $args) = explode(':', $constraint, 2);
                    $args = (array) $args;
                } else {
                    $type = $constraint;
                    $args = null;
                }
            } else {
                $type = isset($constraint['type']) ? $constraint['type'] : null;
                $args = isset($constraint['type_args']) ? $constraint['type_args'] : null;
            }

            if ($type && $inspector->validateConstraint($type, $value, $args) === true) {
                return true;
            }
        }

        return 'Value does not satisfy complex constraints';
    }
}
