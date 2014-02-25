<?php

namespace GuzzleHttp\Service\Guzzle\Description;

use GuzzleHttp\Url;

/**
 * Represents a Guzzle service description
 */
class GuzzleDescription
{
    /** @var array Array of {@see OperationInterface} objects */
    private $operations = [];

    /** @var array Array of API models */
    private $models = [];

    /** @var string Name of the API */
    private $name;

    /** @var string API version */
    private $apiVersion;

    /** @var string Summary of the API */
    private $description;

    /** @var array Any extra API data */
    private $extraData = [];

    /** @var string baseUrl/basePath */
    private $baseUrl;

    /** @var SchemaFormatter */
    private $formatter;

    /**
     * @param array $config  Service description data
     * @param array $options Custom options to apply to the description
     *     - formatter: Can provide a custom SchemaFormatter class
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config, array $options = [])
    {
        // Keep a list of default keys used in service descriptions that is
        // later used to determine extra data keys.
        static $defaultKeys = ['name', 'models', 'apiVersion', 'description'];

        // Pull in the default configuration values
        foreach ($defaultKeys as $key) {
            if (isset($config[$key])) {
                $this->{$key} = $config[$key];
            }
        }

        // Set the baseUrl
        $this->baseUrl = Url::fromString(isset($config['baseUrl']) ? $config['baseUrl'] : '');

        // Ensure that the models and operations properties are always arrays
        $this->models = (array) $this->models;
        $this->operations = (array) $this->operations;

        // We want to add operations differently than adding the other properties
        $defaultKeys[] = 'operations';

        // Create operations for each operation
        if (isset($config['operations'])) {
            foreach ($config['operations'] as $name => $operation) {
                if (!($operation instanceof Operation) && !is_array($operation)) {
                    throw new \InvalidArgumentException('Invalid operation in '
                        . 'service description: ' . gettype($operation));
                }
                $this->operations[$name] = $operation;
            }
        }

        // Get all of the additional properties of the service description and
        // store them in a data array
        foreach (array_diff(array_keys($config), $defaultKeys) as $key) {
            $this->extraData[$key] = $config[$key];
        }

        // Configure the schema formatter
        if (isset($options['formatter'])) {
            $this->formatter = $options['formatter'];
        } else {
            static $defaultFormatter;
            if (!$defaultFormatter) {
                $defaultFormatter = new SchemaFormatter();
            }
            $this->formatter = $defaultFormatter;
        }
    }

    /**
     * Get the basePath/baseUrl of the description
     *
     * @return Url
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * Get the API operations of the service
     *
     * @return Operation[] Returns an array of {@see Operation} objects
     */
    public function getOperations()
    {
        return $this->operations;
    }

    /**
     * Check if the service has an operation by name
     *
     * @param string $name Name of the operation to check
     *
     * @return bool
     */
    public function hasOperation($name)
    {
        return isset($this->operations[$name]);
    }

    /**
     * Get an API operation by name
     *
     * @param string $name Name of the command
     *
     * @return Operation
     * @throws \InvalidArgumentException if the operation is not found
     */
    public function getOperation($name)
    {
        if (!$this->hasOperation($name)) {
            throw new \InvalidArgumentException("No operation found named $name");
        }

        // Lazily create operations as they are retrieved
        if (!($this->operations[$name] instanceof Operation)) {
            $this->operations[$name] = new Operation($this->operations[$name], $this);
        }

        return $this->operations[$name];
    }

    /**
     * Get a shared definition structure.
     *
     * @param string $id ID/name of the model to retrieve
     *
     * @return Parameter
     * @throws \InvalidArgumentException if the model is not found
     */
    public function getModel($id)
    {
        if (!$this->hasModel($id)) {
            throw new \InvalidArgumentException("No model found named $id");
        }

        // Lazily create models as they are retrieved
        if (!($this->models[$id] instanceof Parameter)) {
            $this->models[$id] = new Parameter(
                $this->models[$id] + array('name' => $id),
                ['description' => $this]
            );
        }

        return $this->models[$id];
    }

    /**
     * Check if the service description has a model by name.
     *
     * @param string $id Name/ID of the model to check
     *
     * @return bool
     */
    public function hasModel($id)
    {
        return isset($this->models[$id]);
    }

    /**
     * Get the API version of the service
     *
     * @return string
     */
    public function getApiVersion()
    {
        return $this->apiVersion;
    }

    /**
     * Get the name of the API
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get a summary of the purpose of the API
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Format a parameter using named formats.
     *
     * @param string $format Format to convert it to
     * @param mixed  $input  Input string
     *
     * @return mixed
     */
    public function format($format, $input)
    {
        return $this->formatter->format($format, $input);
    }

    /**
     * Get arbitrary data from the service description that is not part of the
     * Guzzle service description specification.
     *
     * @param string $key Data key to retrieve or null to retrieve all extra
     *
     * @return null|mixed
     */
    public function getData($key = null)
    {
        if ($key === null) {
            return $this->extraData;
        } elseif (isset($this->extraData[$key])) {
            return $this->extraData[$key];
        } else {
            return null;
        }
    }
}
