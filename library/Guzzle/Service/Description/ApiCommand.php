<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

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
    protected $args;

    /**
     * @var string API action name
     */
    protected $name;

    /**
     * @var string HTTP method of the command
     */
    protected $method;

    /**
     * @var int Minimum number of arguments required by the command
     */
    protected $minArgs = 0;

    /**
     * @var string Path routing information of the command to include in the path
     */
    protected $path = '';

    /**
     * @var string Command documentation
     */
    protected $doc;

    /**
     * @var bool Whether or not the command can be sent in a batch request
     */
    protected $canBatch = true;

    /**
     * @var string Concrete class that this ApiCommand is associated with
     */
    protected $concreteCommandClass = 'Guzzle\\Service\\Command\\ClosureCommand';

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
    public function __construct($config)
    {
        $this->name = isset($config['name']) ? trim($config['name']) : '';
        $this->doc = isset($config['doc']) ? trim($config['doc']) : '';
        $this->method = isset($config['method']) ? trim($config['method']) : '';
        $this->minArgs = isset($config['min_args']) ? min(100, max(0, $config['min_args'])) : '';
        $this->canBatch = isset($config['can_batch']) ? $config['can_batch'] : '';
        $this->path = isset($config['path']) ? trim($config['path']) : '';
        
        if (isset($config['class'])) {
            $this->concreteCommandClass = $config['class'];
        }

        // Build the argument array
        if (isset($config['args']) && is_array($config['args'])) {
            $this->args = array();
            foreach ($config['args'] as $argName => $arg) {
                $this->args[$argName] = new Collection($arg);
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
        return $this->method;
    }

    /**
     * Get the concrete command class that implements this command
     *
     * @return string
     */
    public function getConcreteClass()
    {
        return $this->concreteCommandClass;
    }

    /**
     * Get the name of the command
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the documentation for the command
     *
     * @return string
     */
    public function getDoc()
    {
        return $this->doc;
    }

    /**
     * Get the minimum number of required arguments
     *
     * @return int
     */
    public function getMinArgs()
    {
        return $this->minArgs;
    }

    /**
     * Get the path routing information to append to the path of the generated
     * request
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Check if the command can be sent in a batch request
     *
     * @return bool
     */
    public function canBatch()
    {
        return $this->canBatch;
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
        if ($this->minArgs && count($config) < $this->minArgs) {
            $errors[] = $this->name . ' requires at least ' . $this->minArgs . ' arguments';
        }

        $e = Inspector::getInstance()->validateConfig($this->args, $config, false);

        if (is_array($e)) {
            $errors = array_merge($errors, $e);
        }

        return count($errors) == 0 ? true : $errors;
    }
}