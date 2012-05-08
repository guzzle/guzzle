<?php

namespace Guzzle\Service\Description;

use Guzzle\Common\Collection;

/**
 * Data object holding the information of an API command
 */
class ApiCommand
{
    /**
     * @var string Default command class to use when none is specified
     */
    const DEFAULT_COMMAND_CLASS = 'Guzzle\\Service\\Command\\DynamicCommand';

    /**
     * @var array Parameters
     */
    protected $params = array();

    /**
     * @var string Name of the command
     */
    protected $name;

    /**
     * @var string Documentation
     */
    protected $doc;

    /**
     * @var string HTTP method
     */
    protected $method;

    /**
     * @var string HTTP URI of the command
     */
    protected $uri;

    /**
     * @var string Class of the command object
     */
    protected $class;

    /**
     * Constructor
     *
     * @param array $config Array of configuration data using the following keys
     *      string name Name of the command
     *      string doc Method documentation
     *      string method HTTP method of the command
     *      string uri (optional) URI routing information of the command
     *      string class (optional) Concrete class that implements this command
     *      array params Associative array of parameters for the command with each
     *          parameter containing the following keys:
     *
     *          name - Parameter name
     *          type - Type of variable (boolean, integer, string, array, class name, etc...)
     *          required - Whether or not the parameter is required
     *          default - Default value
     *          doc - Documentation
     *          min_length - Minimum length
     *          max_length - Maximum length
     *          location - One of query, path, header, or body
     *          static - Whether or not the param can be changed from this value
     *          prepend - Text to prepend when adding this value to a location
     *          append - Text to append when adding to a location
     *          filters - Comma separated list of filters to run the value through.  Must be a callable
     *                   Can call static class methods by separating the class and function with ::
     */
    public function __construct(array $config)
    {
        $this->name = isset($config['name']) ? trim($config['name']) : '';
        $this->doc = isset($config['doc']) ? trim($config['doc']) : '';
        $this->method = isset($config['method']) ? trim($config['method']) : '';

        $this->uri = isset($config['uri']) ? trim($config['uri']) : '';
        if (!$this->uri) {
            // Add backwards compatibility with the path attribute
            $this->uri = isset($config['path']) ? trim($config['path']) : '';
        }

        $this->class = isset($config['class']) ? trim($config['class']) : self::DEFAULT_COMMAND_CLASS;

        if (isset($config['params']) && is_array($config['params'])) {
            foreach ($config['params'] as $name => $param) {
                $this->params[$name] = $param instanceof ApiParam ? $param : new ApiParam($param);
            }
        }
    }

    /**
     * Get as an array
     *
     * @return true
     */
    public function toArray()
    {
        return array(
            'name'   => $this->name,
            'doc'    => $this->doc,
            'method' => $this->method,
            'uri'    => $this->uri,
            'class'  => $this->class,
            'params' => $this->params
        );
    }

    /**
     * Get the params of the command
     *
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Get a single parameter of the command
     *
     * @param string $param Parameter to retrieve by name
     *
     * @return ApiParam|null
     */
    public function getParam($param)
    {
        return isset($this->params[$param]) ? $this->params[$param] : null;
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
        return $this->class;
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
     * Get the URI that will be merged into the generated request
     *
     * @return string
     */
    public function getUri()
    {
        return $this->uri;
    }
}
