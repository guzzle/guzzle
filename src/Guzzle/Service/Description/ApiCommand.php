<?php

namespace Guzzle\Service\Description;

use Guzzle\Common\Collection;
use Guzzle\Common\Inspector;

/**
 * Data object holding the information of an API command
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ApiCommand
{
    /**
     * @var array Arguments
     */
    protected $args = array();

    /**
     * @var array Configuration data
     */
    protected $config = array();

    /**
     * Constructor
     *
     * @param array $config Array of configuration data using the following keys
     *      string name Name of the command
     *      tring doc Method documentation
     *      string method HTTP method of the command
     *      string path (optional) Path routing information of the command to include in the path
     *      string min_args (optional) The minimum number of required args
     *      bool can_batch (optional) Can the command be sent in a batch request
     *      string class (optional) Concrete class that implements this command
     *      array args Associative array of arguments for the command with each
     *          argument containing the following keys:
     *
     *          name - Argument name
     *          type - Type of variable (boolean, integer, string, array, class name, etc...)
     *          required - Whether or not the argument is required
     *          default - Default value of the argument
     *          doc - Documentation for the argument
     *          min_length - Minimum argument length
     *          max_length - Maximum argument length
     *          location - One of query, path, header, or body
     *          static - Whether or not the argument can be changed from this value
     *          prepend - Text to prepend when adding this value to a location
     *          append - Text to append when adding to a location
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->config['name'] = isset($config['name']) ? trim($config['name']) : '';
        $this->config['doc'] = isset($config['doc']) ? trim($config['doc']) : '';
        $this->config['method'] = isset($config['method']) ? trim($config['method']) : '';
        $this->config['min_args'] = isset($config['min_args']) ? min(100, max(0, $config['min_args'])) : 0;
        $this->config['can_batch'] = isset($config['can_batch']) ? $config['can_batch'] : '';
        $this->config['path'] = isset($config['path']) ? trim($config['path']) : '';
        $this->config['class'] = isset($config['class']) ? trim($config['class']) : 'Guzzle\\Service\\Command\\ClosureCommand';

        // Build the argument array
        if (isset($config['args']) && is_array($config['args'])) {
            $this->args = array();
            foreach ($config['args'] as $argName => $arg) {
                if ($arg instanceof Collection) {
                    $this->args[$argName] = $arg;
                } else {
                    $this->args[$argName] = new Collection($arg);
                }
            }
        }
    }

    /**
     * Get the arguments of the command
     *
     * @return array
     */
    public function getArgs()
    {
        return $this->args;
    }

    /**
     * Get a single argument of the command
     *
     * @param string $argument Argument to retrieve
     *
     * @return Collection|null
     */
    public function getArg($arg)
    {
        foreach ($this->args as $name => $a) {
            if ($name == $arg) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Get the HTTP method of the command
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->config['method'];
    }

    /**
     * Get the concrete command class that implements this command
     *
     * @return string
     */
    public function getConcreteClass()
    {
        return $this->config['class'];
    }

    /**
     * Get the name of the command
     *
     * @return string
     */
    public function getName()
    {
        return $this->config['name'];
    }

    /**
     * Get the documentation for the command
     *
     * @return string
     */
    public function getDoc()
    {
        return $this->config['doc'];
    }

    /**
     * Get the minimum number of required arguments
     *
     * @return int
     */
    public function getMinArgs()
    {
        return $this->config['min_args'];
    }

    /**
     * Get the path routing information to append to the path of the generated
     * request
     *
     * @return string
     */
    public function getPath()
    {
        return $this->config['path'];
    }

    /**
     * Check if the command can be sent in a batch request
     *
     * @return bool
     */
    public function canBatch()
    {
        return $this->config['can_batch'];
    }

    /**
     * Validate that the supplied configuration options satisfy the constraints
     * of the command
     *
     * @param Collection $option Configuration options
     *
     * @return bool|array Returns TRUE on success or an array of error messages
     *      on error
     */
    public function validate(Collection $config)
    {
        $errors = array();
        // Validate that the right number of args has been supplied
        if ($this->config['min_args'] && count($config) < $this->config['min_args']) {
            $errors[] = $this->config['name'] . ' requires at least '
                . $this->config['min_args'] . ' arguments';
        }

        $e = Inspector::getInstance()->validateConfig($this->args, $config, false);
        if (is_array($e)) {
            $errors = array_merge($errors, $e);
        }

        return count($errors) == 0 ? true : $errors;
    }
}