<?php

namespace Guzzle\Service\Builder;

use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Http\ClientInterface;
use Guzzle\Service\Exception\ServiceBuilderException;
use Guzzle\Service\Exception\ServiceNotFoundException;

/**
 * Service builder to generate service builders and service clients from configuration settings
 */
class ServiceBuilder extends AbstractHasDispatcher implements ServiceBuilderInterface, \ArrayAccess, \Serializable
{
    /**
     * @var array Service builder configuration data
     */
    protected $builderConfig = array();

    /**
     * @var array Instantiated client objects
     */
    protected $clients = array();

    /**
     * @var ServiceBuilderLoader Cached instance of the service builder loader
     */
    protected static $cachedFactory;

    /**
     * Create a new ServiceBuilder using configuration data sourced from an
     * array, .json|.js file, SimpleXMLElement, or .xml file.
     *
     * @param array|string $config           The full path to an .xml or .js|.json file, or an associative array
     * @param array        $globalParameters Array of global parameters to pass to every service as it is instantiated.
     *
     * @return ServiceBuilderInterface
     * @throws ServiceBuilderException if a file cannot be opened
     * @throws ServiceNotFoundException when trying to extend a missing client
     */
    public static function factory($config = null, array $globalParameters = array())
    {
        // @codeCoverageIgnoreStart
        if (!static::$cachedFactory) {
            static::$cachedFactory = new ServiceBuilderLoader();
        }
        // @codeCoverageIgnoreEnd

        return self::$cachedFactory->load($config, $globalParameters);
    }

    /**
     * Construct a new service builder
     *
     * @param array $serviceBuilderConfig Service configuration settings:
     *     - name: Name of the service
     *     - class: Client class to instantiate using a factory method
     *     - params: array of key value pair configuration settings for the builder
     */
    public function __construct(array $serviceBuilderConfig)
    {
        $this->builderConfig = $serviceBuilderConfig;
    }

    /**
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array('service_builder.create_client');
    }

    /**
     * Restores the service builder from JSON
     *
     * @param string $serialized JSON data to restore from
     */
    public function unserialize($serialized)
    {
        $this->builderConfig = json_decode($serialized, true);
    }

    /**
     * Represents the service builder as a string
     *
     * @return array
     */
    public function serialize()
    {
        return json_encode($this->builderConfig);
    }

    /**
     * {@inheritdoc}
     */
    public function get($name, $throwAway = false)
    {
        if (!isset($this->builderConfig[$name])) {
            throw new ServiceNotFoundException('No service is registered as ' . $name);
        }

        if (!$throwAway && isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        // Convert references to the actual client
        foreach ($this->builderConfig[$name]['params'] as &$v) {
            if (is_string($v) && 0 === strpos($v, '{') && strlen($v) - 1 == strrpos($v, '}')) {
                $v = $this->get(trim(str_replace(array('{', '}'), '', $v)));
            }
        }

        $class = $this->builderConfig[$name]['class'];
        $client = $class::factory($this->builderConfig[$name]['params']);

        if (!$throwAway) {
            $this->clients[$name] = $client;
        }

        // Dispatch an event letting listeners know a client was created
        $this->dispatch('service_builder.create_client', array(
            'client' => $client
        ));

        return $client;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $service)
    {
        $this->builderConfig[$key] = $service;

        return $this;
    }

    /**
     * Register a client by name with the service builder
     *
     * @param string          $offset Name of the client to register
     * @param ClientInterface $value  Client to register
     */
    public function offsetSet($offset, $value)
    {
        $this->set($offset, $value);
    }

    /**
     * Remove a registered client by name
     *
     * @param string $offset Client to remove by name
     */
    public function offsetUnset($offset)
    {
        unset($this->builderConfig[$offset]);
    }

    /**
     * Check if a client is registered with the service builder by name
     *
     * @param string $offset Name to check to see if a client exists
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->builderConfig[$offset]);
    }

    /**
     * Get a registered client by name
     *
     * @param string $offset Registered client name to retrieve
     *
     * @return ClientInterface
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }
}
