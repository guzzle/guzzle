<?php

namespace Guzzle\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Service\Exception\ValidationException;
use Guzzle\Service\Description\ApiParam;

/**
 * Inpects configuration options versus defined parameters, adding default
 * values where appropriate, performing type validation on config settings, and
 * validating input vs output data.
 *
 * The Reflection class can parse @guzzle specific parameters in a class's
 * docblock comment and validate that a specified {@see Collection} object
 * has the default arguments specified, has the required arguments set, and
 * that the passed arguments that match the typehints specified in the
 * annotation.
 *
 * The following is the format for @guzzle arguments:
 * @guzzle argument_name [default="default value"] [required="true|false"] [type="registered constraint name"] [type_args=""] [doc="Description of argument"]
 *
 * Here's an example:
 * @guzzle my_argument default="hello" required="true" doc="Set the argument to control the widget..."
 */
class Inspector
{
    const GUZZLE_ANNOTATION = '@guzzle';

    /**
     * @var Inspector Cached Inspector instance
     */
    private static $instance;

    /**
     * @var array Cache of parsed doc blocks
     */
    protected $cache = array();

    /**
     * @var array Array of aliased constraints
     */
    protected $constraints = array();

    /**
     * @var Cache of instantiated constraints
     */
    protected $constraintCache = array();

    /**
     * @var bool
     */
    protected $typeValidation = true;

    /**
     * Constructor to create a new Inspector
     */
    public function __construct()
    {
        $base = 'Guzzle\\Common\\Validation\\';
        $this->constraints = array(
            'blank'     => array($base . 'Blank', null),
            'not_blank' => array($base . 'NotBlank', null),
            'integer'   => array($base . 'Numeric', null),
            'float'     => array($base . 'Numeric', null),
            'string'    => array($base . 'Type', array('type' => 'string')),
            'file'      => array($base . 'Type', array('type' => 'file')),
            'bool'      => array($base . 'Bool', null),
            'boolean'   => array($base . 'Bool', null),
            'email'     => array($base . 'Email', null),
            'ip'        => array($base . 'Ip', null),
            'url'       => array($base . 'Url', null),
            'class'     => array($base . 'IsInstanceOf', null),
            'type'      => array($base . 'Type', null),
            'ctype'     => array($base . 'Ctype', null),
            'choice'    => array($base . 'Choice', null),
            'enum'      => array($base . 'Choice', null),
            'regex'     => array($base . 'Regex', null),
            'date'      => array($base . 'Type', array('type' => 'string')),
            'date_time' => array($base . 'Type', array('type' => 'string')),
            'time'      => array($base . 'Numeric', null)
        );
    }

    /**
     * Get an instance of the Inspector
     *
     * @return Inspector
     */
    public static function getInstance()
    {
        // @codeCoverageIgnoreStart
        if (!self::$instance) {
            self::$instance = new self();
        }
        // @codeCoverageIgnoreEnd

        return self::$instance;
    }

    /**
     * Enable/disable type validation of configuration settings.  This is
     * useful for very high performance requirements.
     *
     * @param bool $typeValidation Set to TRUE or FALSE
     *
     * @return Inspector
     */
    public function setTypeValidation($typeValidation)
    {
        $this->typeValidation = $typeValidation;
    }

    /**
     * Validate and prepare configuration parameters
     *
     * @param array $config Configuration values to apply.
     * @param array $defaults (optional) Default parameters
     * @param array $required (optional) Required parameter names
     *
     * @return Collection
     * @throws InvalidArgumentException if a parameter is missing
     */
    public static function prepareConfig(array $config = null, $defaults = null, $required = null)
    {
        $collection = new Collection((array) $defaults);
        foreach ((array) $config as $key => $value) {
            $collection->set($key, $value);
        }
        foreach ((array) $required as $key) {
            if ($collection->hasKey($key) === false) {
                throw new ValidationException(
                    "Config must contain a '{$key}' key"
                );
            }
        }

        return $collection;
    }

    /**
     * Get an array of the registered constraints by name
     *
     * @return array
     */
    public function getRegisteredConstraints()
    {
        return array_map(function($constraint) {
            return $constraint[0];
        }, $this->constraints);
    }

    /**
     * Register a constraint class with a special name
     *
     * @param string $name Name of the constraint to register
     * @param string $class Name of the class or object to use when filtering by this name
     * @param array $default Default values to pass to the constraint
     *
     * @return Inspector
     */
    public function registerConstraint($name, $class, array $default = array())
    {
        $this->constraints[$name] = array($class, $default);
    }

    /**
     * Get the Guzzle arguments from a DocBlock
     *
     * @param string $doc DocBlock to parse
     *
     * @return array Returns an associative array of the parsed docblock params
     */
    public function parseDocBlock($doc)
    {
        $matches = array();
        // Get all of the @guzzle annotations from the class
        preg_match_all('/' . self::GUZZLE_ANNOTATION . '\s+([A-Za-z0-9_\-\.]+)\s*([A-Za-z0-9]+=".+")*/', $doc, $matches);
        if (empty($matches[1])) {
            return array();
        }

        $params = array();
        foreach ($matches[1] as $index => $match) {
            // Add the matched argument to the array keys
            $params[$match] = array();
            if (isset($matches[2])) {
                // Break up the argument attributes by closing quote
                foreach (explode('" ', $matches[2][$index]) as $part) {
                    $attrs = array();
                    // Find the attribute and attribute value
                    preg_match('/([A-Za-z0-9]+)="(.+)"*/', $part, $attrs);
                    if (isset($attrs[1]) && isset($attrs[0])) {
                        // Sanitize the strings
                        if ($attrs[2][strlen($attrs[2]) - 1] == '"') {
                            $attrs[2] = substr($attrs[2], 0, strlen($attrs[2]) - 1);
                        }
                        $params[$match][$attrs[1]] = $attrs[2];
                    }
                }
            }
            $params[$match] = new ApiParam($params[$match]);
        }

        return $params;
    }

    /**
     * Validates that a class has all of the required configuration settings
     *
     * @param string $className Name of the class to use to retrieve args
     * @param Collection $config Configuration settings
     * @param bool $strict (optional) Set to FALSE to allow missing required fields
     * @param bool $validate (optional) Set to TRUE or FALSE to validate data.
     *     Set to false when you only need to add default values and statics.
     *
     * @return array|bool Returns an array of errors or TRUE on success
     * @throws InvalidArgumentException if any args are missing and $strict is TRUE
     */
    public function validateClass($className, Collection $config, $strict = true, $validate = true)
    {
        if (!isset($this->cache[$className])) {
            $reflection = new \ReflectionClass($className);
            $this->cache[$className] = $this->parseDocBlock($reflection->getDocComment());
        }

        return $this->validateConfig($this->cache[$className], $config, $strict, $validate);
    }

    /**
     * Validates that all required args are included in a config object,
     * and if not, throws an InvalidArgumentException with a helpful error message.  Adds
     * default args to the passed config object if the parameter was not
     * set in the config object.
     *
     * @param array $params Params to validate
     * @param Collection $config Configuration settings
     * @param bool $strict (optional) Set to FALSE to allow missing required fields
     * @param bool $validate (optional) Set to TRUE or FALSE to validate data.
     *     Set to false when you only need to add default values and statics.
     *
     * @return array|bool Returns an array of errors or TRUE on success
     *
     * @throws InvalidArgumentException if any args are missing and $strict is TRUE
     */
    public function validateConfig(array $params, Collection $config, $strict = true, $validate = true)
    {
        $errors = array();

        foreach ($params as $name => $arg) {

            // Set the default or static value if it is not set
            $configValue = $arg->getValue($config->get($name));

            // Inject configuration information into the config value
            if (is_string($configValue)) {
                $configValue = Guzzle::inject($configValue, $config);
            }

            // Ensure that required arguments are set
            if ($validate && $arg->getRequired() && ($configValue === null || $configValue === '')) {
                $errors[] = 'Requires that the ' . $name . ' argument be supplied.' . ($arg->getDoc() ? '  (' . $arg->getDoc() . ').' : '');
                continue;
            }

            // Ensure that the correct data type is being used
            if ($validate && $this->typeValidation && $configValue !== null && $argType = $arg->getType()) {
                $validation = $this->validateConstraint($argType, $configValue);
                if ($validation !== true) {
                    $errors[] = $validation;
                    continue;
                }
            }

            // Run the value through attached filters
            $configValue = $arg->filter($configValue);
            $config->set($name, $configValue);

            // Check the length values if validating data
            if ($validate) {
                $argMinLength = $arg->getMinLength();
                if ($argMinLength && strlen($configValue) < $argMinLength) {
                    $errors[] = 'Requires that the ' . $name . ' argument be >= ' . $arg->getMinLength() . ' characters.';
                }

                $argMaxLength = $arg->getMaxLength();
                if ($argMaxLength && strlen($configValue) > $argMaxLength) {
                    $errors[] = 'Requires that the ' . $name . ' argument be <= ' . $arg->getMaxLength() . ' characters.';
                }
            }

            $config->set($name, $configValue);
        }

        if (empty($errors)) {
            return true;
        } else if ($strict) {
            throw new ValidationException('Validation errors: ' . implode("\n", $errors));
        }

        return $errors;
    }

    /**
     * Get a constraint by name
     *
     * @param string $name Constraint name
     *
     * @return ConstraintInterface
     * @throws InvalidArgumentException if the constraint is not registered
     */
    public function getConstraint($name)
    {
        if (!isset($this->constraints[$name])) {
            throw new InvalidArgumentException($name . ' has not been registered');
        }

        if (!isset($this->constraintCache[$name])) {
            $c = $this->constraints[$name][0];
            $this->constraintCache[$name] = new $c();
        }

        return $this->constraintCache[$name];
    }

    /**
     * Validate a constraint by name: e.g. "type:Guzzle\Common\Collection";
     * type:string; choice:a,b,c; choice:'a','b','c'; etc...
     *
     * @param string $name Constraint to retrieve with optional CSV args after colon
     * @param mixed $value Value to validate
     *
     * @return bool|string Returns TRUE if valid, or an error message if invalid
     */
    public function validateConstraint($name, $value)
    {
        $parts = explode(':', $name, 2);
        $name = $parts[0];

        $constraint = $this->getConstraint($name);

        if (empty($parts[1])) {
            $args = $this->constraints[$name][1];
        } elseif (strpos($parts[1], ',')) {
            $args = str_getcsv($parts[1], ',', "'");
        } else {
            $args = array($parts[1]);
        }

        return $constraint->validate($value, $args);
    }
}
