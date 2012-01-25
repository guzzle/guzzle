<?php

namespace Guzzle\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validator;
use Symfony\Component\Validator\Mapping\Loader\StaticMethodLoader;
use Symfony\Component\Validator\Mapping\ClassMetadataFactory;

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
     * @var Cached Inspector instance
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
     * @var Validator
     */
    protected $validator;

    /**
     * Constructor to create a new Inspector
     */
    public function __construct()
    {
        $base = 'Symfony\\Component\\Validator\\Constraints\\';
        $this->constraints = array(
            'blank'     => array($base . 'Blank', null),
            'not_blank' => array($base . 'NotBlank', null),
            'integer'   => array($base . 'Type', array('type' => 'integer')),
            'float'     => array($base . 'Type', array('type' => 'float')),
            'string'    => array($base . 'Type', array('type' => 'string')),
            'date'      => array($base . 'Date', null),
            'date_time' => array($base . 'DateTime', null),
            'time'      => array($base . 'Time', null),
            'boolean'   => array($base . 'Choice', array('choices' => array('true', 'false', '0', '1'))),
            'country'   => array($base . 'Country', null),
            'email'     => array($base . 'Email', null),
            'ip'        => array($base . 'Ip', null),
            'language'  => array($base . 'Language', null),
            'locale'    => array($base . 'Locale', null),
            'url'       => array($base . 'Url', null),
            'file'      => array($base . 'File', null),
            'image'     => array($base . 'Image', null),
            'class'     => array($base . 'Type', null),
            'type'      => array($base . 'Type', null),
            'choice'    => array($base . 'Choice', null),
            'enum'      => array($base . 'Choice', null),
            'regex'     => array($base . 'Regex', null)
        );
    }

    /**
     * Get an instance of the Inspector
     *
     * @return Reflection
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
                throw new \InvalidArgumentException(
                    "Config must contain a '{$key}' key"
                );
            }
        }

        return $collection;
    }

    /**
     * Set the validator to use with the inspector
     *
     * @param Validator $validator
     *
     * @return Inspector
     */
    public function setValidator(Validator $validator)
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Get the validator associated with the inspector.  A default validator
     * will be created if none has already been associated
     *
     * @return Validator
     */
    public function getValidator()
    {
        if (!$this->validator) {
            $this->validator = new Validator(new ClassMetadataFactory(new StaticMethodLoader()), new ConstraintValidatorFactory());
        }

        return $this->validator;
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
        }

        return $params;
    }

    /**
     * Validates that a class has all of the required configuration settings
     *
     * @param string $class Name of the class to use to retrieve args
     * @param Collection $config Configuration settings
     * @param bool $strict (optional) Set to FALSE to allow missing required fields
     *
     * @return array|bool Returns an array of errors or TRUE on success
     *
     * @throws InvalidArgumentException if any args are missing and $strict is TRUE
     */
    public function validateClass($className, Collection $config, $strict = true)
    {
        if (!isset($this->cache[$className])) {
            $reflection = new \ReflectionClass($className);
            $this->cache[$className] = $this->parseDocBlock($reflection->getDocComment());
        }

        return $this->validateConfig($this->cache[$className], $config, $strict);
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
     *
     * @return array|bool Returns an array of errors or TRUE on success
     *
     * @throws InvalidArgumentException if any args are missing and $strict is TRUE
     */
    public function validateConfig(array $params, Collection $config, $strict = true)
    {
        $errors = array();

        foreach ($params as $name => $arg) {

            if (is_array($arg)) {
                $arg = new Collection($arg);
            }

            // Set the default value if it is not set
            if ($arg->get('static') || ($arg->get('default') && !$config->get($name))) {
                $check = $arg->get('static', $arg->get('default'));
                if ($check === 'true') {
                    $config->set($name, true);
                } else if ($check == 'false') {
                    $config->set($name, false);
                } else {
                    $config->set($name, $check);
                }
            }

            // Inject configuration information into the config value
            if (is_scalar($config->get($name)) && strpos($config->get($name), '{{') !== false) {
                $config->set($name, Guzzle::inject($config->get($name), $config));
            }

            // Ensure that required arguments are set
            if ($arg->get('required') && !$config->get($name)) {
                $errors[] = 'Requires that the ' . $name . ' argument be supplied.' . ($arg->get('doc') ? '  (' . $arg->get('doc') . ').' : '');
                continue;
            }

            // Skip further validation if the arg is not set
            if ($config->hasKey($name) === false) {
                continue;
            }

            // Ensure that the correct data type is being used
            if ($arg->get('type')) {
                $constraint = $this->getConstraint($arg->get('type'));
                $result = $this->getValidator()->validateValue($config->get($name), $constraint);
                if (!empty($result)) {
                    $errors = array_merge($errors, array_map(function($message) {
                        return $message->getMessage();
                    }, $result->getIterator()->getArrayCopy()));
                }
            }

            // Run the value through attached filters
            if ($arg->get('filters')) {
                foreach (explode(',', $arg->get('filters')) as $filter) {
                    $config->set($name, call_user_func(trim($filter), $config->get($name)));
                }
            }

            // Check the length values
            if ($arg->get('min_length') && strlen($config->get($name)) < $arg->get('min_length')) {
                $errors[] = 'Requires that the ' . $name . ' argument be >= ' . $arg->get('min_length') . ' characters.';
            }
            if ($arg->get('max_length') && strlen($config->get($name)) > $arg->get('max_length')) {
                $errors[] = 'Requires that the ' . $name . ' argument be <= ' . $arg->get('max_length') . ' characters.';
            }
        }

        if (empty($errors)) {
            return true;
        } else if ($strict) {
            throw new \InvalidArgumentException('Validation errors: ' . implode("\n", $errors));
        }

        return $errors;
    }

    /**
     * Get a constraint by name: e.g. "type:Guzzle\Common\Collection"
     *
     * @param string $name Name of the constraint to retrieve
     *
     * @return Contraint
     */
    public function getConstraint($name)
    {
        $parts = array_map('trim', explode(':', $name, 2));
        $name = $parts[0];

        if (!isset($this->constraints[$name])) {
            throw new \InvalidArgumentException($name . ' has not been registered');
        }

        if (!empty($parts[1])) {
            $args = strpos($parts[1], ',') ? str_getcsv($parts[1], ',', "'") : $parts[1];
        } else {
            $args = $this->constraints[$name][1];
        }

        $class = $this->constraints[$name][0];

        return new $class($args);
    }
}