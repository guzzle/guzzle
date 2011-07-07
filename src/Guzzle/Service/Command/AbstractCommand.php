<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Inspector;
use Guzzle\Common\Collection;
use Guzzle\Common\NullObject;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\ClientInterface;

/**
 * Command object to handle preparing and processing client requests and
 * responses of the requests
 *
 * @author Michael Dowling <michael@guzzlephp.org>
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
     * @var bool Whether or not the command can be batched
     */
    protected $canBatch = true;

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

        // Add arguments and validate the command
        if ($apiCommand) {
            $this->apiCommand = $apiCommand;
            Inspector::getInstance()->validateConfig($apiCommand->getArgs(), $this, false);
        } else if (!($this instanceof ClosureCommand)) {
            Inspector::getInstance()->validateClass(get_class($this), $this, false);
        }

        if (!$this->get('headers') instanceof Collection) {
            $this->set('headers', new Collection());
        }
        
        $this->init();
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
            
            return implode('.', array_map(array('Guzzle\\Common\\Inflector', 'snake'), $parts));
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
     * Get whether or not the command can be batched
     *
     * @return bool
     */
    public function canBatch()
    {
        return $this->canBatch;
    }

    /**
     * Execute the command
     *
     * @return Command
     * @throws RuntimeException if a client has not been associated with the command
     */
    public function execute()
    {
        if (!$this->client) {
            throw new \RuntimeException('A Client object must be associated with the command before it can be executed from the context of the command.');
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
     * @throws RuntimeException if the command has not been executed
     */
    public function getRequest()
    {
        if (!$this->request) {
            throw new \RuntimeException('The command must be prepared before retrieving the request');
        }
        
        return $this->request;
    }

    /**
     * Get the response object associated with the command
     *
     * @return Response
     * @throws RuntimeException if the command has not been executed
     */
    public function getResponse()
    {
        if (!$this->isExecuted()) {
            throw new \RuntimeException('The command must be executed before retrieving the response');
        }
        
        return $this->request->getResponse();
    }

    /**
     * Get the result of the command
     *
     * @return Response By default, commands return a Response
     *      object unless overridden in a subclass
     * @throws RuntimeException if the command has not been executed
     */
    public function getResult()
    {
        if (!$this->isExecuted()) {
            throw new \RuntimeException('The command must be executed before retrieving the result');
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
     * @throws RuntimeException if a client object has not been set previously
     *      or in the prepare()
     */
    public function prepare()
    {
        if (!$this->isPrepared()) {
            if (!$this->client) {
                throw new \RuntimeException('A Client object must be associated with the command before it can be prepared.');
            }

            // Fail on missing required arguments when it is not a ClosureCommand
            if (!($this instanceof ClosureCommand)) {
                if ($this->apiCommand) {
                    Inspector::getInstance()->validateConfig($this->apiCommand->getArgs(), $this);
                } else {
                    Inspector::getInstance()->validateClass(get_class($this), $this, true);
                }
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
    protected function init()
    {
        return;
    }

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
     */
    protected function process()
    {
        $this->result = $this->getRequest()->getResponse();

        // Is the body an XML document?  If so, set the result to be a SimpleXMLElement
        if (preg_match('/^\s*(text\/xml|application\/xml).*$/', $this->result->getContentType())) {
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