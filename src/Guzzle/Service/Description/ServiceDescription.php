<?php

namespace Guzzle\Service\Description;

/**
 * A ServiceDescription stores service information based on a service document
 */
class ServiceDescription implements ServiceDescriptionInterface
{
    /**
     * @var array Array of {@see ApiCommandInterface} objects
     */
    protected $commands = array();

    /**
     * @var ServiceDescriptionFactoryInterface Factory used in factory method
     */
    protected static $descriptionFactory;

    /**
     * {@inheritdoc}
     * @param string|array $config  File to build or array of command information
     * @param array        $options Service description factory options
     */
    public static function factory($config, array $options = null)
    {
        // @codeCoverageIgnoreStart
        if (!self::$descriptionFactory) {
            self::$descriptionFactory = new ServiceDescriptionAbstractFactory();
        }
        // @codeCoverageIgnoreEnd

        return self::$descriptionFactory->build($config);
    }

    /**
     * Create a new ServiceDescription
     *
     * @param array $commands Array of {@see ApiCommandInterface} objects
     */
    public function __construct(array $commands = array())
    {
        $this->commands = $commands;
    }

    /**
     * Serialize the service description
     *
     * @return string
     */
    public function serialize()
    {
        return json_encode(array_map(function($command) {
            // Convert ApiCommands into arrays
            return $command->toArray();
        }, $this->commands));
    }

    /**
     * Unserialize the service description
     *
     * @param string|array $json JSON data
     */
    public function unserialize($json)
    {
        $this->commands = array_map(function($data) {
            // Convert params to ApiParam objects
            $data['params'] = array_map(function($param) {
                return new ApiParam($param);
            }, $data['params']);
            // Convert commands into ApiCommands
            return new ApiCommand($data);
        }, json_decode($json, true));
    }

    /**
     * Get the API commands of the service
     *
     * @return array Returns an array of {@see ApiCommandInterface} objects
     */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * Check if the service has a command by name
     *
     * @param string $name Name of the command to check
     *
     * @return bool
     */
    public function hasCommand($name)
    {
        return array_key_exists($name, $this->commands);
    }

    /**
     * Get an API command by name
     *
     * @param string $name Name of the command
     *
     * @return ApiCommandInterface|null
     */
    public function getCommand($name)
    {
        return $this->hasCommand($name) ? $this->commands[$name] : null;
    }
}
