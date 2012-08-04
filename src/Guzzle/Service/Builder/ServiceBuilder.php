<?php

namespace Guzzle\Service\Builder;

use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Http\ClientInterface;
use Guzzle\Service\Builder\ServiceBuilderAbstractFactory;
use Guzzle\Service\Exception\ServiceBuilderException;
use Guzzle\Service\Exception\ServiceNotFoundException;

/**
 * Service builder to generate service builders and service clients from
 * configuration settings
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
     * @var ServiceBuilderAbstractFactory
     */
    protected static $defaultFactory;

    /**
     * Create a new ServiceBuilder using configuration data sourced from an
     * array, .json|.js file, SimpleXMLElement, or .xml file.
     *
     * @param array|string|\SimpleXMLElement $data An instantiated
     *     SimpleXMLElement containing configuration data, the full path to an
     *     .xml or .js|.json file, or an associative array of data
     * @param array $globalParameters Array of global parameters to
     *     pass to every service as it is instantiated.
     *
     * @return ServiceBuilderInterface
     * @throws ServiceBuilderException if a file cannot be opened
     * @throws ServiceNotFoundException when trying to extend a missing client
     */
    public static function factory($config = null, array $globalParameters = null)
    {
        // @codeCoverageIgnoreStart
        if (!self::$defaultFactory) {
            self::$defaultFactory = new ServiceBuilderAbstractFactory();
        }
        // @codeCoverageIgnoreEnd

        return self::$defaultFactory->build($config, $globalParameters);
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
     * Get a client using a registered builder
     *
     * @param string $name      Name of the registered client to retrieve
     * @param bool   $throwAway Set to TRUE to not store the client for later retrieval from the ServiceBuilder
     *
     * @return ClientInterface
     * @throws ServiceNotFoundException when a client cannot be found by name
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
            if (0 === strpos($v, '{') && strlen($v) - 1 == strrpos($v, '}')) {
                $v = $this->get(trim(str_replace(array('{', '}'), '', $v)));
            }
        }

        $client = call_user_func(
            array($this->builderConfig[$name]['class'], 'factory'),
            $this->builderConfig[$name]['params']
        );

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
     * Register a client by name with the service builder
     *
     * @param string $name  Name of the client to register
     * @param mixed  $value Service to register
     *
     * @return ServiceBuilderInterface
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
