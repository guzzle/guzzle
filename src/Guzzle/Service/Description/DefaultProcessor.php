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
     * @var bool Whether or not integers are converted to strings when an integer is received for a string input
     */
    protected $castIntegerToStringType;

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
     * @param bool $castIntegerToStringType Set to true to convert integers into strings when a required type is a
     *                                      string and the input value is an integer. Defaults to true.
     */
    public function __construct($castIntegerToStringType = true)
    {
        $this->castIntegerToStringType = $castIntegerToStringType;
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

        // if the value is null and the parameter is not required or is static, then skip any further recursion
        if ((null === $value && $param->getRequired() == false) || $param->getStatic()) {
            return true;
        }

        $errors = array();
        // If a name is set then update the path so that validation messages are more helpful
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
                    // if properties were found, the validate each property of the value
                    foreach ($properties as $property) {
                        $this->validateProperty($property, $value, $path, $depth, $errors);
                    }
                }
                $additional = $param->getAdditionalProperties();
                if ($additional !== true) {
                    // If additional properties were found, then validate each against the additionalProperties attr.
                    $this->validateAdditionalProperties($additional, $properties, $value, $errors, $path, $depth);
                }
            }

            if ($tempSet && empty($value)) {
                $value = null;
            }

        } elseif ($type == 'array' && $param->getItems() && is_array($value)) {
            foreach ($value as $i => &$item) {
                // Validate each item in an array against the items attribute of the schema
                $e = $this->recursiveProcess($param->getItems(), $item, $path . "[{$i}]", $depth + 1);
                if ($e !== true) {
                    $errors = array_merge($errors, $e);
                }
            }
        }

        // If the value is required and the type is not null, then there is an error if the value is not set
        if ($param->getRequired() && ($value === null || $value === '') && $type != 'null') {
            $message = "{$path} is " . ($param->getType() ? ('a required ' . $param->getType()) : 'required');
            if ($param->getDescription()) {
                $message .= ': ' . $param->getDescription();
            }
            $errors[] = $message;
        } else {

            // Validate that the type is correct. If the type is string but an integer was passed, the class can be
            // instructed to cast the integer to a string to pass validation. This is the default behavior.
            if ($type && (!$type = $this->determineType($param, $value))) {
                if ($this->castIntegerToStringType && $param->getType() == 'string' && is_integer($value)) {
                    $value = (string) $value;
                } else {
                    $errors[] = "{$path} must be of type " . implode(' or ', (array) $param->getType());
                }
            }

            // Validate string specific options
            if ($type == 'string') {
                // Strings can have enums which are a list of predefined values
                if (($enum = $param->getEnum()) && !in_array($value, $enum)) {
                    $errors[] = "{$path} must be one of " . implode(' or ', array_map(function ($s) {
                        return '"' . addslashes($s) . '"';
                    }, $enum));
                }
                // Strings can have a regex pattern that the value must match
                if (($pattern = $param->getPattern()) && !preg_match($pattern, $value)) {
                    $errors[] = "{$path} must match the following regular expression: {$pattern}";
                }
            }

            // Validate min attribute contextually based on the value type
            if ($min = $param->getMin()) {
                if (($type == 'integer' || $type == 'numeric') && $value < $min) {
                    $errors[] = "{$path} must be greater than or equal to {$min}";
                } elseif ($type == 'string' && strlen($value) < $min) {
                    $errors[] = "{$path} length must be greater than or equal to {$min}";
                } elseif ($type == 'array' && count($value) < $min) {
                    $errors[] = "{$path} must contain {$min} or more elements";
                }
            }

            // Validate max attribute contextually based on the value type
            if ($max = $param->getMax()) {
                if (($type == 'integer' || $type == 'numeric') && $value > $max) {
                    $errors[] = "{$path} must be less than or equal to {$max}";
                } elseif ($type == 'string' && strlen($value) > $max) {
                    $errors[] = "{$path} length must be less than or equal to {$max}";
                } elseif ($type == 'array' && count($value) > $max) {
                    $errors[] = "{$path} must contain {$max} or fewer elements";
                }
            }
        }

        // Determine what the response should be
        if (empty($errors)) {
            // If no errors were found, then filter the value and return true
            $value = $param->filter($value);
            return true;
        } elseif ($depth == 0) {
            // If errors were found and this is the outer recursive function, then return a sorted list of errors
            sort($errors);
            return $errors;
        } else {
            // If errors were found in a recursive call, then return them. They will be merged in to the parent scope.
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

        // Determine the keys that were specified that were not listed in the properties of the schema
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
                // if additionalProperties is set to false and there are additionalProperties in the values, then fail
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
