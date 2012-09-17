<?php

namespace Guzzle\Service\Description;

/**
 * Default parameter validator
 */
class DefaultProcessor implements ProcessorInterface
{
    /**
     * @var self Cache instance of the object
     */
    protected static $instance;

    /**
     * Get a cached instance
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Parameter $param, &$value)
    {
        return $this->recursiveProcess($param, $value);
    }

    /**
     * Recursively validate a parameter
     *
     * @param Parameter $param API parameter being validated
     * @param mixed     $value Value to validate and process. The value may change during this process.
     * @param string    $path  Current validation path (used for error reporting)
     * @param int       $depth Current depth in the validation process
     *
     * @return bool|array Returns true if valid, or an array of error messages if invalid
     */
    protected function recursiveProcess(Parameter $param, &$value, $path = '', $depth = 0)
    {
        // Update the value by adding default or static values
        $value = $param->getValue($value);

        if ((null === $value && $param->getRequired() == false) || $param->getStatic()) {
            return true;
        }

        $errors = array();
        if ($name = $param->getName()) {
            $path .= "[{$name}]";
        }

        $type = $param->getType();

        if ($type == 'object') {

            // Objects are either associative arrays, \ArrayAccess, or some other object
            $instanceOf = $param->getInstanceOf();
            if ($instanceOf && !($value instanceof $instanceOf)) {
                $errors[] = "{$path} must be an instance of {$instanceOf}";
            }

            // Determine whether or not this "value" has properties and should be traversed
            $traverse = $tempSet = false;
            if (is_array($value)) {
                // Ensure that the array is associative and not numerically indexed
                if (isset($value[0])) {
                    $errors[] = "{$path} must be an associative array of properties. Got a numerically indexed array.";
                } else {
                    $traverse = true;
                }
            } elseif ($value instanceof \ArrayAccess) {
                $traverse = true;
            } elseif ($value === null) {
                // Attempt to let the contents be built up by default values if possible
                $tempSet = true;
                $value = array();
                $traverse = true;
            }

            if ($traverse) {
                if ($properties = $param->getProperties()) {
                    foreach ($properties as $property) {
                        $this->validateProperty($property, $value, $path, $depth, $errors);
                    }
                }
                $additional = $param->getAdditionalProperties();
                if ($additional !== true) {
                    $this->validateAdditionalProperties($additional, $properties, $value, $errors, $path, $depth);
                }
            }

            if ($tempSet && empty($value)) {
                $value = null;
            }

        } elseif ($type == 'array' && $param->getItems() && is_array($value)) {
            foreach ($value as $i => &$item) {
                $e = $this->recursiveProcess($param->getItems(), $item, $path . "[{$i}]", $depth + 1);
                if ($e !== true) {
                    $errors = array_merge($errors, $e);
                }
            }
        }

        if ($param->getRequired() && ($value === null || $value === '') && $type != 'null') {
            $message = "{$path} is " . ($param->getType() ? ('a required ' . $param->getType()) : 'required');
            if ($param->getDescription()) {
                $message .= ': ' . $param->getDescription();
            }
            $errors[] = $message;
        } else {

            if ($type && (!$type = $this->determineType($param, $value))) {
                $errors[] = "{$path} must be of type " . implode(' or ', (array) $param->getType());
            }

            if ($type == 'string') {
                if (($enum = $param->getEnum()) && !in_array($value, $enum)) {
                    $errors[] = "{$path} must be one of " . implode(' or ', array_map(function ($s) {
                        return '"' . addslashes($s) . '"';
                    }, $enum));
                }
                if (($pattern = $param->getPattern()) && !preg_match($pattern, $value)) {
                    $errors[] = "{$path} must match the following regular expression: {$pattern}";
                }
            }

            if ($min = $param->getMin()) {
                if (($type == 'integer' || $type == 'numeric') && $value < $min) {
                    $errors[] = "{$path} must be greater than or equal to {$min}";
                } elseif ($type == 'string' && strlen($value) < $min) {
                    $errors[] = "{$path} length must be greater than or equal to {$min}";
                } elseif ($type == 'array' && count($value) < $min) {
                    $errors[] = "{$path} must contain {$min} or more elements";
                }
            }

            if ($max = $param->getMax()) {
                if (($type == 'integer' || $type == 'numeric') && $value > $max) {
                    $errors[] = "{$path} must be less than or equal to {$max}";
                } elseif ($type == 'string' && strlen($value) > $max) {
                    $errors[] = "{$path} length must be greater than or equal to {$max}";
                } elseif ($type == 'array' && count($value) > $max) {
                    $errors[] = "{$path} must contain {$max} or fewer elements";
                }
            }
        }

        if (empty($errors)) {
            $value = $param->filter($value);
            return true;
        } elseif ($depth == 0) {
            sort($errors);
            return $errors;
        } else {
            return $errors;
        }
    }

    /**
     * Validate additional properties. If set to false and there are properties specified that are not specifically
     * specified in the schema, then fail. If set to a schema, then validate all additional properties against it.
     *
     * @param Parameter|bool $additional Additional properties
     * @param array          $properties Allowable properties
     * @param mixed          $value      Value being validated
     * @param array          $errors     Errors collection
     * @param string         $path       Current validation path
     * @param int            $depth      Current validation depth
     */
    protected function validateAdditionalProperties($additional, array $properties, &$value, &$errors, $path, $depth)
    {
        if (is_array($value)) {
            $keys = array_keys($value);
        } else {
            foreach ($value as $k => $v) {
                $keys[] = $k;
            }
        }

        $diff = array_diff($keys, array_keys($properties));

        if (!empty($diff)) {
            // Determine which keys are not in the properties
            if ($additional instanceOf Parameter) {
                foreach ($diff as $key) {
                    $v = &$value[$key];
                    $e = $this->recursiveProcess($additional, $v, "{$path}[{$key}]", $depth);
                    if ($e !== true) {
                        $errors = array_merge($errors, $e);
                    }
                }
            } else {
                $keys = array_keys($value);
                $errors[] = sprintf('%s[%s] is not an allowed property', $path, reset($keys));
            }
        }
    }

    /**
     * Process a property
     *
     * @param Parameter $property API parameter property
     * @param mixed     $value    Value to process
     * @param string    $path     Current validation path
     * @param int       $depth    Current validation depth
     * @param array     $errors   Errors encountered
     */
    protected function validateProperty(Parameter $property, &$value, $path, $depth, array &$errors)
    {
        $name = $property->getName();
        $current = isset($value[$name]) ? $value[$name] : null;
        $e = $this->recursiveProcess($property, $current, $path, $depth + 1);
        if ($e !== true) {
            $errors = array_merge($errors, $e);
        } elseif ($current) {
            $value[$name] = $current;
        }
    }

    /**
     * From the allowable types, determine the type that the variable matches
     *
     * @param Parameter $param Parameter that is being validated
     * @param mixed     $value Value to determine the type
     *
     * @return string|bool
     */
    protected function determineType(Parameter $param, $value)
    {
        foreach ((array) $param->getType() as $type) {
            if ($this->checkType($type, $value)) {
                return $type;
            }
        }

        return false;
    }

    /**
     * Check if a value is a particular type
     *
     * @param string $type  Type to check
     * @param string $value Value to check
     *
     * @return bool
     */
    protected function checkType($type, $value)
    {

        if ($type && $type != 'any') {
            switch ($type) {
                case 'string':
                    return is_string($value) || (is_object($value) && method_exists($value, '__toString'));
                case 'integer':
                    return is_integer($value);
                case 'numeric':
                    return is_numeric($value);
                case 'object':
                    return is_array($value) || is_object($value);
                case 'array':
                    return is_array($value);
                case 'boolean':
                    return is_bool($value);
                case 'null':
                    return !$value;
            }
        }

        return true;
    }
}
