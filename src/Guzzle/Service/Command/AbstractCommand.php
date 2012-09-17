<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Description\OperationInterface;
use Guzzle\Service\ClientInterface;
use Guzzle\Service\Exception\CommandException;

/**
 * Command object to handle preparing and processing client requests and responses of the requests
 */
abstract class AbstractCommand extends Collection implements CommandInterface
{
    const HEADERS_OPTION = 'headers';

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
     * @var OperationInterface API information about the command
     */
    protected $operation;

    /**
     * @var mixed callable
     */
    protected $onComplete;

    /**
     * Constructor
     *
     * @param array|Collection    $parameters Collection of parameters to set on the command
     * @param OperationInterface $operation Command definition from description
     */
    public function __construct($parameters = null, OperationInterface $operation = null)
    {
        parent::__construct($parameters);
        $this->operation = $operation ?: $this->createOperation();
        foreach ($this->operation->getParams() as $name => $arg) {
            $currentValue = $this->get($name);
            $configValue = $arg->getValue($currentValue);
            // If default or static values are set, then this should always be updated on the config object
            if ($currentValue !== $configValue) {
                $this->set($name, $configValue);
            }
        }

        $headers = $this->get(self::HEADERS_OPTION);
        if (!$headers instanceof Collection) {
            $this->set(self::HEADERS_OPTION, new Collection((array) $headers));
        }

        // You can set a command.on_complete option in your parameters to set an onComplete callback
        if ($onComplete = $this->get('command.on_complete')) {
            $this->remove('command.on_complete');
            $this->setOnComplete($onComplete);
        }

        $this->init();
    }

    /**
     * Custom clone behavior
     */
    public function __clone()
    {
        $this->request = null;
        $this->result = null;
    }

    /**
     * Execute the command in the same manner as calling a function
     *
     * @return mixed Returns the result of {@see AbstractCommand::execute}
     */
    public function __invoke()
    {
        return $this->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->operation->getName();
    }

    /**
     * Get the API command information about the command
     *
     * @return OperationInterface
     */
    public function getOperation()
    {
        return $this->operation;
    }

    /**
     * {@inheritdoc}
     */
    public function setOnComplete($callable)
    {
        if (!is_callable($callable)) {
            throw new InvalidArgumentException('The onComplete function must be callable');
        }

        $this->onComplete = $callable;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function execute()
    {
        if (!$this->client) {
            throw new CommandException('A Client object must be associated with the command before it can be executed from the context of the command.');
        }

        return $this->client->execute($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function setClient(ClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getRequest()
    {
        if (!$this->request) {
            throw new CommandException('The command must be prepared before retrieving the request');
        }

        return $this->request;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponse()
    {
        if (!$this->isExecuted()) {
            throw new CommandException('The command must be executed before retrieving the response');
        }

        return $this->request->getResponse();
    }

    /**
     * {@inheritdoc}
     */
    public function getResult()
    {
        if (!$this->isExecuted()) {
            throw new CommandException('The command must be executed before retrieving the result');
        }

        if (null === $this->result) {
            $this->process();
            // Call the onComplete method if one is set
            if ($this->onComplete) {
                call_user_func($this->onComplete, $this);
            }
        }

        return $this->result;
    }

    /**
     * {@inheritdoc}
     */
    public function setResult($result)
    {
        $this->result = $result;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isPrepared()
    {
        return $this->request !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function isExecuted()
    {
        return $this->request !== null && $this->request->getState() == 'complete';
    }

    /**
     * {@inheritdoc}
     */
    public function prepare()
    {
        if (!$this->isPrepared()) {
            if (!$this->client) {
                throw new CommandException('A Client object must be associated with the command before it can be prepared.');
            }

            // Fail on missing required arguments, and change parameters via filters
            $this->operation->validate($this);
            $this->build();

            // Add custom request headers set on the command
            if ($headers = $this->get(self::HEADERS_OPTION)) {
                foreach ($headers as $key => $value) {
                    $this->request->setHeader($key, $value);
                }
            }

            // Add any curl options to the request
            if ($options = CurlHandle::parseCurlConfig($this->getAll())) {
                $this->request->getCurlOptions()->merge($options);
            }
        }

        return $this->getRequest();
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestHeaders()
    {
        return $this->get(self::HEADERS_OPTION);
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
     * Override this hook to create an operation for concrete commands
     *
     * @return OperationInterface
     */
    protected function createOperation()
    {
        return new Operation(array('name' => get_class($this)));
    }

    /**
     * Create the result of the command after the request has been completed.
     * Override this method in subclasses to customize this behavior
     */
    protected function process()
    {
        $this->result = DefaultResponseParser::getInstance()->parse($this);
    }
}
