<?php

namespace Guzzle\Service\Description;

use Guzzle\Common\Collection;

/**
 * Data object holding the information of an API command
 */
class ApiCommand
{
    /**
     * @var array Parameters
     */
    protected $params = array();

    /**
     * @var array Configuration data
     */
    protected $config = array();

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
        $this->config = $config;
        $this->config['name'] = isset($config['name']) ? trim($config['name']) : '';
        $this->config['doc'] = isset($config['doc']) ? trim($config['doc']) : '';
        $this->config['method'] = isset($config['method']) ? trim($config['method']) : '';
        $this->config['uri'] = isset($config['uri']) ? trim($config['uri']) : '';
        if (!$this->config['uri']) {
            // Add backwards compatibility with the path attribute
            $this->config['uri'] = isset($config['path']) ? trim($config['path']) : '';
        }

        $this->config['class'] = isset($config['class']) ? trim($config['class']) : 'Guzzle\\Service\\Command\\DynamicCommand';

        if (isset($config['params']) && is_array($config['params'])) {
            foreach ($config['params'] as $paramName => $param) {
                $this->params[$paramName] = $param instanceof Collection ? $param : new Collection($param);
            }
        }
    }

    /**
     * Get as an array
     *
     * @return true
     */
    public function getData()
    {
        return array_merge($this->config, array(
            'params' => $this->params
        ));
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
     * @return Collection|null
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
     * Get the URI that will be merged into the generated request
     *
     * @return string
     */
    public function getUri()
    {
        return $this->config['uri'];
    }
}