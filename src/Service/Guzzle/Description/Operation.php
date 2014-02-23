<?php

namespace GuzzleHttp\Service\Guzzle\Description;

/**
 * Guzzle operation
 */
class Operation
{
    /** @var array Hashmap of properties that can be specified */
    private static $properties = ['name' => true, 'httpMethod' => true,
        'uri' => true, 'class' => true, 'responseClass' => true,
        'responseType' => true, 'responseNotes' => true, 'notes' => true,
        'summary' => true, 'documentationUrl' => true, 'deprecated' => true,
        'data' => true, 'parameters' => true, 'additionalParameters' => true,
        'errorResponses' => true];

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

    /** @var string This is what is returned from the method */
    private $responseClass;

    /** @var string Type information about the response */
    private $responseType;

    /** @var string Information about the response returned by the operation */
    private $responseNotes;

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
     * - responseClass: (string) This is what is returned from the method. Can
     *   be a primitive, autoloadable class name, or model.
     * - responseNotes: (string) Information about the response returned by the
     *   operation.
     * - responseType: (string) One of 'primitive', 'class', or 'model'. If not
     *   specified, this value will be automatically inferred based on whether
     *   or not there is a model matching the name, if a matching class name is
     *   found, or set to 'primitive' by default.
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
    public function __construct(array $config = array(), GuzzleDescription $description)
    {
        $this->description = $description;

        // Get the intersection of the available properties and properties set on the operation
        foreach (array_intersect_key($config, self::$properties) as $key => $value) {
            $this->{$key} = $value;
        }

        $this->deprecated = (bool) $this->deprecated;
        $this->errorResponses = $this->errorResponses ?: array();
        $this->data = $this->data ?: array();

        if (!$this->responseClass) {
            $this->responseClass = 'array';
            $this->responseType = 'primitive';
        } elseif ($this->responseType) {
            // Set the response type to perform validation
            $this->setResponseType($this->responseType);
        } else {
            // A response class was set and no response type was set, so guess
            // what the type is based on context.
            $this->inferResponseType();
        }

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
     * @return array
     */
    public function getParams()
    {
        return $this->parameters;
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
     * Get what is returned from the method. Can be a primitive, class name, or
     * model. For example, the responseClass could be 'array', which would
     * inherently use a responseType of 'primitive'. Using a class name would
     * set a responseType of 'class'. Specifying a model by ID will use a
     * responseType of 'model'.
     *
     * @return string|null
     */
    public function getResponseClass()
    {
        return $this->responseClass;
    }

    /**
     * Get information about how the response is unmarshalled: One of
     * 'primitive', 'class', or 'model'.
     *
     * @return string
     */
    public function getResponseType()
    {
        return $this->responseType;
    }

    /**
     * Get notes about the response of the operation
     *
     * @return string|null
     */
    public function getResponseNotes()
    {
        return $this->responseNotes;
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

    /**
     * Infer the response type from the responseClass value
     */
    private function inferResponseType()
    {
        static $primitives = ['array' => 1, 'boolean' => 1, 'string' => 1,
            'integer' => 1, '' => 1];

        if (isset($primitives[$this->responseClass])) {
            $this->responseType = 'primitive';
        } elseif ($this->description->hasModel($this->responseClass)) {
            $this->responseType = 'model';
        } else {
            $this->responseType = 'class';
        }
    }

    /**
     * Set the responseType of the operation.
     *
     * @param string $responseType Response type information
     * @throws \InvalidArgumentException
     */
    private function setResponseType($responseType)
    {
        static $types = ['primitive' => true, 'class' => true, 'model' => true];
        if (!isset($types[$responseType])) {
            throw new \InvalidArgumentException('responseType must be one of '
                . implode(', ', array_keys($types)));
        }
        $this->responseType = $responseType;
    }
}
