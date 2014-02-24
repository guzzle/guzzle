<?php

namespace GuzzleHttp\Service\Guzzle\Description;

/**
 * Guzzle operation
 */
class Operation
{
    /** @var array Hashmap of properties that can be specified */
    private static $properties = ['name' => true, 'httpMethod' => true,
        'uri' => true, 'class' => true, 'responseModel' => true,
        'notes' => true, 'summary' => true, 'documentationUrl' => true,
        'deprecated' => true, 'data' => true, 'parameters' => true,
        'additionalParameters' => true, 'errorResponses' => true];

    /** @var array Parameters */
    private $parameters = [];

    /** @var Parameter Additional parameters schema */
    private $additionalParameters;

    /** @var string Name of the command */
    private $name;

    /** @var string HTTP method */
    private $httpMethod;

    /** @var string This is a short summary of what the operation does */
    private $summary;

    /** @var string A longer text field to explain the behavior of the operation. */
    private $notes;

    /** @var string Reference URL providing more information about the operation */
    private $documentationUrl;

    /** @var string HTTP URI of the command */
    private $uri;

    /** @var string The model name used for processing the response */
    private $responseModel;

    /** @var bool Whether or not the command is deprecated */
    private $deprecated;

    /** @var array Array of errors that could occur when running the command */
    private $errorResponses;

    /** @var GuzzleDescription */
    private $description;

    /** @var array Extra operation information */
    private $data;

    /**
     * Builds an Operation object using an array of configuration data.
     *
     * - name: (string) Name of the command
     * - httpMethod: (string) HTTP method of the operation
     * - uri: (string) URI template that can create a relative or absolute URL
     * - parameters: (array) Associative array of parameters for the command.
     *   Each value must be an array that is used to create {@see Parameter}
     *   objects.
     * - summary: (string) This is a short summary of what the operation does
     * - notes: (string) A longer description of the operation.
     * - documentationUrl: (string) Reference URL providing more information
     *   about the operation.
     * - responseModel: (string) The model name used for processing response.
     * - deprecated: (bool) Set to true if this is a deprecated command
     * - errorResponses: (array) Errors that could occur when executing the
     *   command. Array of hashes, each with a 'code' (the HTTP response code),
     *   'phrase' (response reason phrase or description of the error), and
     *   'class' (a custom exception class that would be thrown if the error is
     *   encountered).
     * - data: (array) Any extra data that might be used to help build or
     *   serialize the operation
     * - additionalParameters: (null|array) Parameter schema to use when an
     *   option is passed to the operation that is not in the schema
     *
     * @param array             $config      Array of configuration data
     * @param GuzzleDescription $description Service description used to resolve models if $ref tags are found
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config = [], GuzzleDescription $description)
    {
        $this->description = $description;

        // Get the intersection of the available properties and properties set on the operation
        foreach (array_intersect_key($config, self::$properties) as $key => $value) {
            $this->{$key} = $value;
        }

        $this->deprecated = (bool) $this->deprecated;
        $this->errorResponses = $this->errorResponses ?: [];
        $this->data = $this->data ?: [];

        // Parameters need special handling when adding
        if ($this->parameters) {
            foreach ($this->parameters as $name => $param) {
                if (!is_array($param)) {
                    throw new \InvalidArgumentException('Parameters must be arrays');
                }
                $param['name'] = $name;
                $this->parameters[$name] = new Parameter(
                    $param,
                    ['description' => $this->description]
                );
            }
        }

        if ($this->additionalParameters) {
            if (is_array($this->additionalParameters)) {
                $this->additionalParameters = new Parameter(
                    $this->additionalParameters,
                    ['description' => $this->description]
                );
            }
        }
    }

    /**
     * Get the service description that the operation belongs to
     *
     * @return GuzzleDescription
     */
    public function getServiceDescription()
    {
        return $this->description;
    }

    /**
     * Get the params of the operation
     *
     * @return Parameter[]
     */
    public function getParams()
    {
        return $this->parameters;
    }

    /**
     * Get additionalParameters of the operation
     *
     * @return Parameter|null
     */
    public function getAdditionalParameters()
    {
        return $this->additionalParameters;
    }

    /**
     * Check if the operation has a specific parameter by name
     *
     * @param string $name Name of the param
     *
     * @return bool
     */
    public function hasParam($name)
    {
        return isset($this->parameters[$name]);
    }

    /**
     * Get a single parameter of the operation
     *
     * @param string $name Parameter to retrieve by name
     *
     * @return Parameter|null
     */
    public function getParam($name)
    {
        return isset($this->parameters[$name]) ? $this->parameters[$name] : null;
    }

    /**
     * Get the HTTP method of the operation
     *
     * @return string|null
     */
    public function getHttpMethod()
    {
        return $this->httpMethod;
    }

    /**
     * Get the name of the operation
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get a short summary of what the operation does
     *
     * @return string|null
     */
    public function getSummary()
    {
        return $this->summary;
    }

    /**
     * Get a longer text field to explain the behavior of the operation
     *
     * @return string|null
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * Get the documentation URL of the operation
     *
     * @return string|null
     */
    public function getDocumentationUrl()
    {
        return $this->documentationUrl;
    }

    /**
     * Get the name of the model used for processing the response.
     *
     * @return string
     */
    public function getResponseModel()
    {
        return $this->responseModel;
    }

    /**
     * Get whether or not the operation is deprecated
     *
     * @return bool
     */
    public function getDeprecated()
    {
        return $this->deprecated;
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

    /**
     * Get the errors that could be encountered when executing the operation
     *
     * @return array
     */
    public function getErrorResponses()
    {
        return $this->errorResponses;
    }

    /**
     * Get extra data from the operation
     *
     * @param string $name Name of the data point to retrieve or null to
     *     retrieve all of the extra data.
     *
     * @return mixed|null
     */
    public function getData($name = null)
    {
        if ($name === null) {
            return $this->data;
        } elseif (isset($this->data[$name])) {
            return $this->data[$name];
        } else {
            return null;
        }
    }
}
