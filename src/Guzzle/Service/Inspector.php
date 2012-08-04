<?php

namespace Guzzle\Service;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Validation\ConstraintInterface;
use Guzzle\Service\Exception\ValidationException;

/**
 * Prepares configuration settings with default values and ensures that required
 * values are set.  Holds references to validation constraints and their default
 * values.
 */
class Inspector
{
    /**
     * @var Inspector Cached Inspector instance
     */
    private static $instance;

    /**
     * @var array Array of aliased constraints
     */
    protected $constraints = array();

    /**
     * @var array Cache of instantiated constraints
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
            'array'     => array($base . 'Type', array('type' => 'array')),
            'bool'      => array($base . 'Bool', null),
            'boolean'   => array($base . 'Bool', null),
            'email'     => array($base . 'Email', null),
            'ip'        => array($base . 'Ip', null),
            'url'       => array($base . 'Url', null),
            'class'     => array($base . 'IsInstanceOf', null),
            'type'      => array($base . 'Type', null),
            'any_match' => array($base . 'AnyMatch', null),
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
     * Validate and prepare configuration parameters
     *
     * @param array $config   Configuration values to apply.
     * @param array $defaults Default parameters
     * @param array $required Required parameter names
     *
     * @return Collection
     * @throws InvalidArgumentException if a parameter is missing
     */
    public static function prepareConfig(array $config = null, array $defaults = null, array $required = null)
    {
        $collection = new Collection($defaults);

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
     * Check if type validation is enabled
     *
     * @return bool
     */
    public function getTypeValidation()
    {
        return $this->typeValidation;
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
     * @param string $name    Name of the constraint to register
     * @param string $class   Name of the class or object to use when filtering by this name
     * @param array  $default Default values to pass to the constraint
     *
     * @return Inspector
     */
    public function registerConstraint($name, $class, array $default = array())
    {
        $this->constraints[$name] = array($class, $default);
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
     * @param string $name  Constraint to retrieve with optional CSV args after colon
     * @param mixed  $value Value to validate
     * @param array  $args  Optional arguments to pass to the type validation
     *
     * @return bool|string Returns TRUE if valid, or an error message if invalid
     */
    public function validateConstraint($name, $value, array $args = null)
    {
        if (!$args) {
            $args = isset($this->constraints[$name][1]) ? $this->constraints[$name][1] : array();
        }

        return $this->getConstraint($name)->validate($value, $args);
    }
}
