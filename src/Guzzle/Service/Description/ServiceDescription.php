<?php

namespace Guzzle\Service\Description;

/**
 * A ServiceDescription stores service information based on a service document
 */
class ServiceDescription implements ServiceDescriptionInterface
{
    /**
     * @var array Array of {@see OperationInterface} objects
     */
    protected $operations = array();

    /**
     * @var array Array of API models
     */
    protected $models = array();

    /**
     * @var string Name of the API
     */
    protected $name;

    /**
     * @var string API version
     */
    protected $apiVersion;

    /**
     * @var string Summary of the API
     */
    protected $description;

    /**
     * @var ServiceDescriptionFactoryInterface Factory used in factory method
     */
    protected static $descriptionFactory;

    /**
     * {@inheritdoc}
     * @param string|array $config  File to build or array of operation information
     * @param array        $options Service description factory options
     */
    public static function factory($config, array $options = null)
    {
        // @codeCoverageIgnoreStart
        if (!self::$descriptionFactory) {
            self::$descriptionFactory = new ServiceDescriptionAbstractFactory();
        }
        // @codeCoverageIgnoreEnd

        return self::$descriptionFactory->build($config, $options);
    }

    /**
     * Create a new ServiceDescription
     *
     * @param array $config Array of configuration data
     */
    public function __construct(array $config = array())
    {
        $this->fromArray($config);
    }

    /**
     * Serialize the service description
     *
     * @return string
     */
    public function serialize()
    {
        $result = array(
            'name'        => $this->name,
            'apiVersion'  => $this->apiVersion,
            'description' => $this->description,
            'operations'  => array(),
        );
        foreach ($this->operations as $name => $operation) {
            $result['operations'][$name] = $operation->toArray();
        }
        if (!empty($this->models)) {
            $result['models'] = array();
            foreach ($this->models as $id => $model) {
                $result['models'][$id] = $model instanceof Parameter ? $model->toArray(): $model;
            }
        }

        return json_encode(array_filter($result));
    }

    /**
     * Unserialize the service description
     *
     * @param string|array $json JSON data
     */
    public function unserialize($json)
    {
        $this->operations = array();
        $this->fromArray(json_decode($json, true));
    }

    /**
     * {@inheritdoc}
     */
    public function getOperations()
    {
        return $this->operations;
    }

    /**
     * {@inheritdoc}
     */
    public function hasOperation($name)
    {
        return isset($this->operations[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function getOperation($name)
    {
        return $this->hasOperation($name) ? $this->operations[$name] : null;
    }

    /**
     * Add a operation to the service description
     *
     * @param OperationInterface $operation Operation to add
     *
     * @return self
     */
    public function addOperation(OperationInterface $operation)
    {
        $this->operations[$operation->getName()] = $operation->setServiceDescription($this);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getModel($id)
    {
        if (isset($this->models[$id])) {
            if (!($this->models[$id] instanceof Parameter)) {
                $this->models[$id] = new Parameter($this->models[$id], $this);
            }
            return $this->models[$id];
        } else {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasModel($id)
    {
        return isset($this->models[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function getApiVersion()
    {
        return $this->apiVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Initialize the state from an array
     *
     * @param array $config Configuration data
     */
    protected function fromArray(array $config)
    {
        if (isset($config['operations'])) {
            foreach ($config['operations'] as $name => $operation) {
                if (!($operation instanceof Operation)) {
                    $operation = new Operation($operation);
                }
                if (!$operation->getName()) {
                   $operation->setName($name);
                }
                $this->addOperation($operation);
            }
        }
        $this->models = isset($config['models']) ? (array) $config['models'] : array();
        $this->apiVersion = isset($config['apiVersion']) ? $config['apiVersion'] : null;
        $this->name = isset($config['name']) ? $config['name'] : null;
        $this->description = isset($config['description']) ? $config['description'] : null;
    }
}
