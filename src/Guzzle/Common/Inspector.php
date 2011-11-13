<?php

namespace Guzzle\Common;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;

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
 * @guzzle argument_name [default="default value"] [required="true|false"] [type="registered filter name"] [doc="Description of argument"]
 *
 * Here's an example:
 * @guzzle my_argument default="hello" required="true" doc="Set the argument to control the widget..."
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Inspector
{
    const GUZZLE_ANNOTATION = '@guzzle';

    /**
     * @var Inspector Singleton instance
     */
    private static $instance;

    /**
     * @var array Cache of parsed doc blocks
     */
    protected $cache = array();

    /**
     * @var array Array of loaded filter objects
     */
    protected $filters = array();

    /**
     * Get an instantiated instance of the Reflection class
     *
     * @return Reflection
     */
    public static function getInstance()
    {
        // @coveCoverageIgnoreStart
        if (!self::$instance) {
            self::$instance = new self();
        }
        // @coveCoverageIgnoreEnd

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
     * Private constructor
     */
    private function __construct()
    {
        $this->filters = array(
            'integer' => array(__NAMESPACE__ . '\\Filter\\IntegerFilter', null, null),
            'float' => array(__NAMESPACE__ . '\\Filter\\FloatFilter', null, null),
            'string' => array(__NAMESPACE__ . '\\Filter\\StringFilter', null, null),
            'timestamp' => array(__NAMESPACE__ . '\\Filter\\TimestampFilter', null, null),
            'date' => array(__NAMESPACE__ . '\\Filter\\DateFilter', null, null),
            'boolean' => array(__NAMESPACE__ . '\\Filter\\BooleanFilter', null, null),
            'class' => array(__NAMESPACE__ . '\\Filter\\ClassFilter', null, null),
            'array' => array(__NAMESPACE__ . '\\Filter\\ArrayFilter', null, null),
            'enum' => array(__NAMESPACE__ . '\\Filter\\EnumFilter', null, null),
            'regex' => array(__NAMESPACE__ . '\\Filter\\RegexFilter', null, null)
        );
    }

    /**
     * Get an array of the registered filters by name
     *
     * @return array
     */
    public function getRegisteredFilters()
    {
        return array_map(function($filter) {
            return $filter[0];
        }, $this->filters);
    }

    /**
     * Register a filter class with a special name
     *
     * @param string $name Name of the filter to register
     * @param string|FilterInterface $class Name of the class or object to use when filtering by this name
     * @param string $default Default value to pass to the filter
     *
     * @return Inspector
     */
    public function registerFilter($name, $class, $default = null)
    {
        $object = null;

        if (is_object($class)) {
            $object = $class;
            $class = get_class($object);
        }

        $this->filters[$name] = array($class, $object, array($default));
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

            $arg = ($arg instanceof Collection) ? $arg : (is_array($arg) ? new Collection($arg) : new Collection());

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
                $result = $this->validate($arg->get('type'), $config->get($name));
                if ($result !== true && $result !== null) {
                    $errors[] = $result;
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
        } else {
            if ($strict) {
                throw new \InvalidArgumentException('Validation errors: ' . implode("\n", $errors));
            } else {
                return $errors;
            }
        }
    }

    /**
     * Get a filter from the registered filters
     *
     * @param string $name Name of the filter to retrieve
     * @param mixed $value Value to validate
     *
     * @return bool|string Returns TRUE on success or a string error message on
     *      failure
     */
    private function validate($name, $value)
    {
        $parts = array_map('trim', explode(':', $name));
        $name = $parts[0];

        if (!isset($this->filters[$name])) {
            throw new \InvalidArgumentException($name . ' has not been registered as a filter');
        }

        // Use supplied arguments or the defaults if they are set
        $args = array_slice($parts, 1);
        if (empty($args)) {
            $args = (array) $this->filters[$name][2];
        }

        if (!isset($this->filters[$name][1])) {
            // Create a new filter and store it in a Flyweight type cache
            $class = $this->filters[$name][0];
            $this->filters[$name][1] = new $class($args);
        } else {
            // Set the passed values
            $this->filters[$name][1]->replace($args);
        }

        return $this->filters[$name][1]->process($value);
    }
}