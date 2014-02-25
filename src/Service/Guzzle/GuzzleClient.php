<?php

namespace GuzzleHttp\Service\Guzzle;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Collection;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Service\CommandException;
use GuzzleHttp\Service\CommandInterface;
use GuzzleHttp\Service\Event\EventWrapper;
use GuzzleHttp\Service\Guzzle\Description\GuzzleDescription;
use GuzzleHttp\Service\Guzzle\Subscriber\PrepareRequest;
use GuzzleHttp\Service\Guzzle\Subscriber\ProcessResponse;
use GuzzleHttp\Service\Guzzle\Subscriber\ValidateInput;

/**
 * Default Guzzle web service client implementation.
 */
class GuzzleClient implements GuzzleClientInterface
{
    use HasEmitterTrait;

    /** @var ClientInterface HTTP client used to send requests */
    private $client;

    /** @var GuzzleDescription Guzzle service description */
    private $description;

    /** @var Collection Service client configuration data */
    private $config;

    /** @var callable Factory used for creating commands */
    private $commandFactory;

    /**
     * @param ClientInterface   $client      Client used to send HTTP requests
     * @param GuzzleDescription $description Guzzle service description
     * @param array             $config      Configuration options
     *     - defaults: Associative array of default command parameters to add
     *       to each command created by the client.
     *     - validate: Specify if command input is validated (defaults to true).
     *       Changing this setting after the client has been created will have
     *       no effect.
     *     - process: Specify if HTTP responses are parsed (defaults to true).
     *       Changing this setting after the client has been created will have
     *       no effect.
     *     - request_locations: Associative array of location types mapping to
     *       RequestLocationInterface objects.
     *     - response_locations: Associative array of location types mapping to
     *       ResponseLocationInterface objects.
     */
    public function __construct(
        ClientInterface $client,
        GuzzleDescription $description,
        array $config = []
    ) {
        $this->client = $client;
        $this->description = $description;
        if (!isset($config['defaults'])) {
            $config['defaults'] = [];
        }
        $this->config = new Collection($config);
        $this->processConfig();
    }

    public function __call($name, array $arguments)
    {
        return $this->execute($this->getCommand($name, $arguments));
    }

    public function getCommand($name, array $args = [])
    {
        $factory = $this->commandFactory;
        // Merge in default command options
        $args += $this->config['defaults'];
        if (!($command = $factory($name, $args, $this))) {
            throw new \InvalidArgumentException("Invalid operation: $name");
        }

        return $command;
    }

    public function execute(CommandInterface $command)
    {
        try {
            $event = EventWrapper::prepareCommand($command, $this);
            if (null !== ($result = $event->getResult())) {
                return $result;
            }
            $request = $event->getRequest();
            return EventWrapper::processCommand(
                $command,
                $this,
                $request,
                $this->client->send($request)
            );
        } catch (CommandException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new CommandException(
                'Error executing the command',
                $this,
                $command,
                null,
                null,
                $e
            );
        }
    }

    public function executeAll($commands, array $options = [])
    {

    }

    public function getHttpClient()
    {
        return $this->client;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getConfig($keyOrPath = null)
    {
        return $keyOrPath === null
            ? $this->config->toArray()
            : $this->config->getPath($keyOrPath);
    }

    public function setConfig($keyOrPath, $value)
    {
        $this->config->setPath($keyOrPath, $value);
    }

    /**
     * Creates a callable function used to create command objects from a
     * service description.
     *
     * @param GuzzleDescription $description Service description
     *
     * @return callable Returns a command factory
     */
    public static function defaultCommandFactory(GuzzleDescription $description)
    {
        return function (
            $name,
            array $args = [],
            GuzzleClientInterface $client
        ) use ($description) {
            // Try with a capital and lowercase first letter
            if (!$description->hasOperation($name)) {
                $name = ucfirst($name);
            }

            if (!($operation = $description->getOperation($name))) {
                return null;
            }

            return new Command($operation, $args, clone $client->getEmitter());
        };
    }

    /**
     * Prepares the client based on the configuration settings of the client.
     */
    protected function processConfig()
    {
        // Use the passed in command factory or a custom factory if provided
        $this->commandFactory = isset($config['command_factory'])
            ? $config['command_factory']
            : self::defaultCommandFactory($this->description);

        // Add event listeners based on the configuration option
        $emitter = $this->getEmitter();

        if (!isset($this->config['validate']) ||
            $this->config['validate'] === true
        ) {
            $emitter->addSubscriber(new ValidateInput());
        }

        $emitter->addSubscriber(new PrepareRequest(
            $this->config['request_locations'] ?: []
        ));

        if (!isset($config['process']) || $config['process'] === true) {
            $emitter->addSubscriber(new ProcessResponse(
                $this->config['response_locations'] ?: []
            ));
        }
    }
}
