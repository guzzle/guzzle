<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Common\NullObject;
use Guzzle\Common\Exception\BadMethodCallException;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\ClientInterface;
use Guzzle\Service\Inspector;
use Guzzle\Service\Inflector;
use Guzzle\Service\Exception\CommandException;
use Guzzle\Service\Exception\JsonException;

/**
 * Command object to handle preparing and processing client requests and
 * responses of the requests
 */
abstract class AbstractCommand extends Collection implements CommandInterface
{
    /**
     * @var ClientInterface Client object used to execute the command
     */
    protected $client;

    /**
     * @var RequestInterface The request object associated with the command
     */
    protected $request;

    /**
     * @var mixed The result of the command
     */
    protected $result;

    /**
     * @var ApiCommand API information about the command
     */
    protected $apiCommand;

    /**
     * Constructor
     *
     * @param array|Collection $parameters (optional) Collection of parameters
     *      to set on the command
     * @param ApiCommand $apiCommand (optional) Command definition from description
     */
    public function __construct($parameters = null, ApiCommand $apiCommand = null)
    {
        parent::__construct($parameters);

        // Add arguments and defaults to the command
        if ($apiCommand) {
            $this->apiCommand = $apiCommand;
            Inspector::getInstance()->validateConfig($apiCommand->getParams(), $this, false, false);
        } else {
            Inspector::getInstance()->validateClass(get_class($this), $this, false, false);
        }

        if (!$this->get('headers') instanceof Collection) {
            $this->set('headers', new Collection((array) $this->get('headers')));
        }

        $this->init();
    }

    /**
     * Enables magic methods for setting parameters.
     *
     * @param string $method Name of the parameter to set
     * @param array  $args   (optional) Arguments to pass to the command
     *
     * @return AbstractCommand
     * @throws BadMethodCallException when a parameter doesn't exist
     */
    public function __call($method, $args = null)
    {
        // Ensure magic method call behavior is enabled
        if (!$this->get('command.magic_method_call')) {
            throw new BadMethodCallException('Magic method calls are disabled '
                . 'for this command.  Consider enabling magic method calls by '
                . 'setting the command.magic_method_call parameter to true.');
        }

        if ($args && strpos($method, 'set') === 0) {
            // Convert the method into the snake cased parameter key
            $key = Inflector::snake(substr($method, 3));

            // If the parameter exists, set it
            if (array_key_exists($key, $this->apiCommand->getParams())) {
                $this->set($key, $args[0]);
                return $this;
            }
        }

        // If the method is not a set method, or the parameter doesn't exist, fail
        throw new BadMethodCallException("Missing method {$method}.");
    }

    /**
     * Get the short form name of the command
     *
     * @return string
     */
    public function getName()
    {
        if ($this->apiCommand) {
            return $this->apiCommand->getName();
        } else {
            // Determine the name of the command based on the relation to the
            // client that executes the command
            $parts = explode('\\', get_class($this));
            while (array_shift($parts) !== 'Command');

            return implode('.', array_map(array('Guzzle\\Service\\Inflector', 'snake'), $parts));
        }
    }

    /**
     * Get the API command information about the command
     *
     * @return ApiCommand|NullObject
     */
    public function getApiCommand()
    {
        return $this->apiCommand ?: new NullObject();
    }

    /**
     * Execute the command
     *
     * @return Command
     * @throws CommandException if a client has not been associated with the command
     */
    public function execute()
    {
        if (!$this->client) {
            throw new CommandException('A Client object must be associated with the command before it can be executed from the context of the command.');
        }

        $this->client->execute($this);

        return $this;
    }

    /**
     * Get the client object that will execute the command
     *
     * @return ClientInterface|null
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set the client objec that will execute the command
     *
     * @param ClientInterface $client The client objec that will execute the command
     *
     * @return Command
     */
    public function setClient(ClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Get the request object associated with the command
     *
     * @return RequestInterface
     * @throws CommandException if the command has not been executed
     */
    public function getRequest()
    {
        if (!$this->request) {
            throw new CommandException('The command must be prepared before retrieving the request');
        }

        return $this->request;
    }

    /**
     * Get the response object associated with the command
     *
     * @return Response
     * @throws CommandException if the command has not been executed
     */
    public function getResponse()
    {
        if (!$this->isExecuted()) {
            throw new CommandException('The command must be executed before retrieving the response');
        }

        return $this->request->getResponse();
    }

    /**
     * Get the result of the command
     *
     * @return Response By default, commands return a Response
     *      object unless overridden in a subclass
     * @throws CommandException if the command has not been executed
     */
    public function getResult()
    {
        if (!$this->isExecuted()) {
            throw new CommandException('The command must be executed before retrieving the result');
        }

        if (null === $this->result) {
            $this->process();
        }

        return $this->result;
    }

    /**
     * Returns TRUE if the command has been prepared for executing
     *
     * @return bool
     */
    public function isPrepared()
    {
        return $this->request !== null;
    }

    /**
     * Returns TRUE if the command has been executed
     *
     * @return bool
     */
    public function isExecuted()
    {
        return $this->request !== null && $this->request->getState() == 'complete';
    }

    /**
     * Prepare the command for executing and create a request object.
     *
     * @return RequestInterface Returns the generated request
     * @throws CommandException if a client object has not been set previously
     *      or in the prepare()
     */
    public function prepare()
    {
        if (!$this->isPrepared()) {
            if (!$this->client) {
                throw new CommandException('A Client object must be associated with the command before it can be prepared.');
            }

            // Fail on missing required arguments
            if ($this->apiCommand) {
                Inspector::getInstance()->validateConfig($this->apiCommand->getParams(), $this, true);
            } else {
                Inspector::getInstance()->validateClass(get_class($this), $this, true);
            }

            $this->build();

            // Add custom request headers set on the command
            $headers = $this->get('headers');
            if ($headers && $headers instanceof Collection) {
                foreach ($headers as $key => $value) {
                    $this->request->setHeader($key, $value);
                }
            }
        }

        return $this->getRequest();
    }

    /**
     * Get the object that manages the request headers that will be set on any
     * outbound requests from the command
     *
     * @return Collection
     */
    public function getRequestHeaders()
    {
        return $this->get('headers', new Collection());
    }

    /**
     * Initialize the command (hook to be implemented in subclasses)
     */
    protected function init() {}

    /**
     * Create the request object that will carry out the command
     */
    abstract protected function build();

    /**
     * Create the result of the command after the request has been completed.
     *
     * Sets the result as the response by default.  If the response is an XML
     * document, this will set the result as a SimpleXMLElement.  If the XML
     * response is invalid, the result will remain the Response, not XML.
     * If an application/json response is received, the result will automat-
     * ically become an array.
     */
    protected function process()
    {
        // Uses the response object by default
        $this->result = $this->getRequest()->getResponse();

        // Is the body an JSON document?  If so, set the result to be an array
        if (stripos($this->result->getContentType(), 'application/json') !== false) {
            $body = trim($this->result->getBody(true));
            if ($body) {
                $decoded = json_decode($body, true);
                if (JSON_ERROR_NONE !== json_last_error()) {
                    throw new JsonException('The response body can not be decoded to JSON', json_last_error());
                }

                $this->result = $decoded;
            }
        } else if (preg_match('/^\s*(text\/xml|application\/xml).*$/', $this->result->getContentType())) {
            // Is the body an XML document?  If so, set the result to be a SimpleXMLElement
            // If the body is available, then parse the XML
            $body = trim($this->result->getBody(true));
            if ($body) {
                // Silently allow parsing the XML to fail
                try {
                    $xml = new \SimpleXMLElement($body);
                    $this->result = $xml;
                } catch (\Exception $e) {}
            }
        }
    }
}
