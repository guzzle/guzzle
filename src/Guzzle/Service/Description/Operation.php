<?php

namespace Guzzle\Service\Description;

use Guzzle\Common\Collection;
use Guzzle\Service\Exception\ValidationException;

/**
 * Data object holding the information of an API command
 */
class Operation implements OperationInterface
{
    /**
     * @var string Default command class to use when none is specified
     */
    const DEFAULT_COMMAND_CLASS = 'Guzzle\\Service\\Command\\OperationCommand';

    /**
     * @var array Parameters
     */
    protected $parameters = array();

    /**
     * @var string Name of the command
     */
    protected $name;

    /**
     * @var string HTTP method
     */
    protected $httpMethod;

    /**
     * @var string This is a short summary of what the operation does
     */
    protected $summary;

    /**
     * @var string A longer text field to explain the behavior of the operation.
     */
    protected $notes;

    /**
     * @var string Reference URL providing more information about the operation
     */
    protected $documentationUrl;

    /**
     * @var string HTTP URI of the command
     */
    protected $uri;

    /**
     * @var string Class of the command object
     */
    protected $class;

    /**
     * @var string This is what is returned from the method
     */
    protected $responseClass;

    /**
     * @var string Information about the response returned by the operation
     */
    protected $responseNotes;

    /**
     * @var bool Whether or not the command is deprecated
     */
    protected $deprecated;

    /**
     * @var array Array of errors that could occur when running the command
     */
    protected $errorResponses;

    /**
     * @var ServiceDescriptionInterface
     */
    protected $description;

    /**
     * @var array Extra operation information
     */
    protected $data;

    /**
     * Builds an Operation object using an array of configuration data:
     * - name:               (string) Name of the command
     * - httpMethod:         (string) HTTP method of the operation
     * - uri:                (string) URI template that can create a relative or absolute URL
     * - class:              (string) Concrete class that implements this command
     * - parameters:         (array) Associative array of parameters for the command. {@see Parameter} for information.
     * - summary:            (string) This is a short summary of what the operation does
     * - notes:              (string) A longer text field to explain the behavior of the operation.
     * - documentationUrl:   (string) Reference URL providing more information about the operation
     * - responseClass:      (string) This is what is returned from the method. Can be a primitive, class name, or model
     * - responseNotes:      (string) Information about the response returned by the operation
     * - deprecated:         (bool) Set to true if this is a deprecated command
     * - errorResponses:     (array)  Errors that could occur when executing the command. Array of hashes, each with a
     *                       'code' (the HTTP response code), 'phrase' (response reason phrase or description of the
     *                       error), and 'class' (a custom exception class that would be thrown if the error is
     *                       encountered).
     * - data:               (array) Any extra data that might be used to help build or serialize the operation
     *
     * @param array                       $config      Array of configuration data
     * @param ServiceDescriptionInterface $description Service description used to resolve models if $ref tags are found
     */
    public function __construct(array $config = array(), ServiceDescriptionInterface $description = null)
    {
        $this->description = $description;
        foreach ($config as $key => $value) {
            $this->{$key} = $value;
        }

        $this->uri = $this->uri ?: '';
        $this->class = $this->class ?: self::DEFAULT_COMMAND_CLASS;
        $this->deprecated = (bool) $this->deprecated;
        $this->errorResponses = $this->errorResponses ?: array();
        $this->data = $this->data ?: array();

        if (!empty($config['parameters'])) {
            foreach ($config['parameters'] as $name => $param) {
                if ($param instanceof Parameter) {
                    $param->setName($name)->setParent($this);
                    $this->parameters[$name] = $param;
                } elseif (is_array($param)) {
                    // Lazily build Parameters when they are requested
                    $param['name'] = $name;
                    $param['parent'] = $this;
                    $this->parameters[$name] = $param;
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        foreach (array(
            'httpMethod', 'uri', 'class', 'summary', 'notes', 'documentationUrl', 'responseClass',
            'responseNotes', 'deprecated'
        ) as $check) {
            if ($value = $this->{$check}) {
                $result[$check] = $value;
            }
        }
        if (!empty($this->data)) {
            $result['data'] = $this->data;
        }
        $result['parameters'] = array();
        foreach ($this->getParams() as $key => $param) {
            $result['parameters'][$key] = $param->toArray();
        }
        if (!empty($this->errorResponses)) {
            $result['errorResponses'] = $this->errorResponses;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getServiceDescription()
    {
        return $this->description;
    }

    /**
     * {@inheritdoc}
     */
    public function setServiceDescription(ServiceDescriptionInterface $description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getParams()
    {
        // Convert any lazily created parameter arrays into Parameter objects
        foreach ($this->parameters as &$param) {
            if (!($param instanceof Parameter)) {
                $param = new Parameter($param, $this->description);
            }
        }

        return $this->parameters;
    }

    /**
     * {@inheritdoc}
     */
    public function getParamNames()
    {
        return array_keys($this->parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function hasParam($name)
    {
        return isset($this->parameters[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function getParam($param)
    {
        if (isset($this->parameters[$param])) {
            // Lazily convert param arrays into Parameter objects
            if (!($this->parameters[$param] instanceof Parameter)) {
                $this->parameters[$param] = new Parameter($this->parameters[$param]);
            }
            return $this->parameters[$param];
        } else {
            return null;
        }
    }

    /**
     * Add a parameter to the command
     *
     * @param Parameter $param Parameter to add
     *
     * @return self
     */
    public function addParam(Parameter $param)
    {
        $this->parameters[$param->getName()] = $param;

        return $this;
    }

    /**
     * Remove a parameter from the command
     *
     * @param string $name Name of the parameter to remove
     *
     * @return self
     */
    public function removeParam($name)
    {
        unset($this->parameters[$name]);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getHttpMethod()
    {
        return $this->httpMethod;
    }

    /**
     * Set the HTTP method of the command
     *
     * @param string $httpMethod Method to set
     *
     * @return self
     */
    public function setHttpMethod($httpMethod)
    {
        $this->httpMethod = $httpMethod;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * Set the concrete class of the command
     *
     * @param string $className Concrete class name
     *
     * @return self
     */
    public function setClass($className)
    {
        $this->class = $className;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the name of the command
     *
     * @param string $name Name of the command
     *
     * @return self
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getSummary()
    {
        return $this->summary;
    }

    /**
     * Set a short summary of what the operation does
     *
     * @param string $summary Short summary of the operation
     *
     * @return self
     */
    public function setSummary($summary)
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * Set a longer text field to explain the behavior of the operation.
     *
     * @param string $notes Notes on the operation
     *
     * @return self
     */
    public function setNotes($notes)
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDocumentationUrl()
    {
        return $this->documentationUrl;
    }

    /**
     * Set the URL pointing to additional documentation on the command
     *
     * @param string $docUrl Documentation URL
     *
     * @return self
     */
    public function setDocumentationUrl($docUrl)
    {
        $this->documentationUrl = $docUrl;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseClass()
    {
        return $this->responseClass;
    }

    /**
     * Set what is returned from the method. Can be a primitive, class name, or model
     *
     * @param string $responseClass Type of response
     *
     * @return self
     */
    public function setResponseClass($responseClass)
    {
        $this->responseClass = $responseClass;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseNotes()
    {
        return $this->responseNotes;
    }

    /**
     * Set notes about the response of the operation
     *
     * @param string $notes Response notes
     *
     * @return self
     */
    public function setResponseNotes($notes)
    {
        $this->responseNotes = $notes;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDeprecated()
    {
        return $this->deprecated;
    }

    /**
     * Set whether or not the command is deprecated
     *
     * @param bool $isDeprecated Set to true to mark as deprecated
     *
     * @return self
     */
    public function setDeprecated($isDeprecated)
    {
        $this->deprecated = $isDeprecated;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Set the URI template of the command
     *
     * @param string $uri URI template to set
     *
     * @return self
     */
    public function setUri($uri)
    {
        $this->uri = $uri;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorResponses()
    {
        return $this->errorResponses;
    }

    /**
     * Add an error to the command
     *
     * @param string $code   HTTP response code
     * @param string $reason HTTP response reason phrase or information about the error
     * @param string $class  Exception class associated with the error
     *
     * @return self
     */
    public function addErrorResponse($code, $reason, $class)
    {
        $this->errorResponses[] = array('code' => $code, 'reason' => $reason, 'class' => $class);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getData($name)
    {
        return isset($this->data[$name]) ? $this->data[$name] : null;
    }

    /**
     * Set a particular data point on the operation
     *
     * @param string $name  Name of the data value
     * @param mixed  $value Value to set
     *
     * @return self
     */
    public function setData($name, $value)
    {
        $this->data[$name] = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws ValidationException when validation errors occur
     */
    public function validate(Collection $config)
    {
        $errors = array();
        foreach ($this->getParams() as $name => $arg) {
            $value = $config->get($name);
            $result = $arg->process($value);
            if ($result !== true) {
                $errors = array_merge($errors, $result);
            }
            // Update the config value if it changed
            if ($value !== $config->get($name)) {
                $config->set($name, $value);
            }
        }

        if (!empty($errors)) {
            $e = new ValidationException('Validation errors: ' . implode("\n", $errors));
            $e->setErrors($errors);
            throw $e;
        }
    }
}
