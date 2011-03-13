<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Inspector;
use Guzzle\Common\Collection;
use Guzzle\Common\Event\Observer;
use Guzzle\Common\Event\AbstractSubject;
use Guzzle\Http\Url;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Curl\CurlConstants;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Command\CommandFactoryInterface;
use Guzzle\Common\NullObject;

/**
 * Client object for executing commands on a webservice.
 *
 * Signals emitted:
 *
 *  event                   context           description
 *  -----                   -------           -----------
 *  request.create          RequestInterface  Created a new request
 *  command.before_execute  CommandInterface  A command is about to execute
 *  command.after_execute   CommandInterface  A command executed
 *  command.create          CommandInterface  A command was created
 *
 * @author  michael@guzzlephp.org
 */
class Client extends AbstractSubject
{
    /**
     * @var Collection Parameter object holding configuration data
     */
    protected $config;

    /**
     * @var ServiceDescription Description of the service and possible commands
     */
    protected $serviceDescription;

    /**
     * @var CommandFactoryInterface Factory used to build API commands
     */
    protected $commandFactory;

    /**
     * @var string Your application's name and version (e.g. MyApp/1.0)
     */
    protected $userApplication = null;

    /**
     * @var Url Injected base URL
     */
    protected $baseUrl;

    /**
     * Client constructor
     *
     * @param array|Collection|string $config Parameters that define how the
     *      client behaves and connects to a webservice.  Pass an array,
     *      Collection object or a string.  When passing a string, it will be
     *      treated as the base_url of the client.
     * @param ServiceDescription $serviceDescription (optional) Description of
     *      the service and the commands that can be taken on the webservice
     * @param CommandFactoryInterface $commandFactory (optional) Command factory
     *      used to create commands by name.
     *
     * @throws ServiceException
     */
    public function __construct($config, ServiceDescription $serviceDescription = null, CommandFactoryInterface $commandFactory = null)
    {
        // Set the configuration object
        if ($config instanceof Collection) {
            $this->config = $config;
        } else if (is_array($config)) {
            $this->config = new Collection($config);
        } else if (is_string($config)) {
            $this->config = new Collection(array(
                'base_url' => $config
            ));
        } else {
            throw new ServiceException(
                '$config must be a string, Collection, or array'
            );
        }

        $this->serviceDescription = $serviceDescription;
        $this->commandFactory = $commandFactory;

        if ($serviceDescription) {
            if (!$this->getConfig('base_url')) {
                $this->config->set('base_url', $serviceDescription->getBaseUrl());
            }

            // Add default arguments and validate the supplied arguments
            Inspector::getInstance()->validateConfig($serviceDescription->getClientArgs(), $this->config, true);
        }

        // Make sure that the service has a base_url specified
        if (!$this->config->get('base_url')) {
            throw new ServiceException('No base_url argument was supplied to the constructor');
        }
        
        $this->setBaseUrl($this->config->get('base_url'));

        $this->init();
    }

    /**
     * Get a configuration setting from the client or all of the configuration
     * settings.  This command should not allow the client config to be
     * modified, so an immutable value is returned
     *
     * @param bool|string $key Configuration value to retrieve.  Set to FALSE
     *      to retrieve all values as an array.
     *
     * @return mixed|array
     */
    public function getConfig($key = false)
    {
        return !$key ? $this->config->getAll() : $this->config->get($key);
    }

    /**
     * Create and return a new {@see RequestInterface} configured for the client
     *
     * @param string $method (optional) HTTP method.  Defaults to GET
     * @param string $uri (optional) Resource URI.  Use an absolute path to
     *      override the base path of the client, or a relative path to append
     *      to the base path of the client.  The URI can contain the
     *      querystring as well.
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     * @return RequestInterface
     */
    public function createRequest($method = RequestInterface::GET, $uri = null, $inject = null)
    {
        // Inject configuration data into the URI if needed
        if ($inject) {
            if (is_array($inject)) {
                $inject = new Collection($inject);
            }
            $uri = Guzzle::inject($uri, $inject);
        }

        if ($uri) {
            // Use absolute URLs as-is
            if (strpos($uri, 'http') === 0) {
                $url = $uri;
            } else {
                $url = clone $this->baseUrl;
                $url = (string) $url->combine($uri);
            }
        } else {
            $url = $this->getBaseUrl();
        }

        return $this->prepareRequest(
            RequestFactory::create($method, $url)
        );
    }

    /**
     * Prepare a request to be sent from the Client by adding client specific
     * behaviors and properties to the request.
     *
     * This method should only be called when using the default RequestFactory
     * is not an option and the request sent from the client must be created
     * manually.
     *
     * @param RequestInterface $request Request to prepare for the client
     *
     * @return RequestInterface
     */
    public function prepareRequest(RequestInterface $request)
    {
        // Add your application name to the request
        if ($this->userApplication) {
            $request->setHeader('User-Agent', trim($this->userApplication . ' ' . $request->getHeader('User-Agent')));
        }

        // Add any curl options that might in the config to the request
        foreach ($this->config->getAll(array('/^curl\..+/')) as $key => $value) {
            $curlOption = CurlConstants::getOptionInt(str_replace('curl.', '', $key));
            if ($curlOption !== false) {
                $curlValue = CurlConstants::getValueInt($value);
                $curlValue = ($curlValue === false) ? $value : $curlValue;
                $request->getCurlOptions()->set($curlOption, $curlValue);
            }
        }

        // Add the cache key filter to requests if one is set on the client
        if ($this->getConfig('cache.key_filter')) {
            $request->getParams()->set('cache.key_filter', $this->getConfig('cache.key_filter'));
        }

        // Attach client observers to the request
        foreach ($this->getEventManager()->getAttached() as $observer) {
            $request->getEventManager()->attach($observer);
        }

        $this->getEventManager()->notify('request.create', $request);

        return $request;
    }

    /**
     * Get a command using the client's CommandFactoryInterface
     *
     * @param string $name Name of the command to retrieve
     * @param array $args (optional) Arguments to pass to the command
     *
     * @return CommandInterface
     * @throws ServiceException if no command factory has been set
     */
    public function getCommand($name, array $args = array())
    {
        if (!$this->commandFactory) {
            throw new ServiceException(
                'No command factory has been set on the client'
            );
        }

        $command = $this->commandFactory->buildCommand($name, $args);
        $command->setClient($this);
        $this->getEventManager()->notify('command.create', $command);

        return $command;
    }

    /**
     * Execute a command and return the response
     *
     * @param CommandInterface|CommandSet $command The command or set to execute
     *
     * @return mixed Returns the result of the executed command's
     *       {@see CommandInterface::getResult} method if a CommandInterface is
     *       passed, or the CommandSet itself if a CommandSet is passed
     *
     * @throws InvalidArgumentException if neither a Command or CommandSet is passed
     * @throws Command\CommandSetException if the set contains commands associated
     *      with other clients
     */
    public function execute($command)
    {
        if ($command instanceof CommandInterface) {

            $command->prepare($this);
            $this->getEventManager()->notify('command.before_send', $command);
            $command->getRequest()->send();
            $this->getEventManager()->notify('command.after_send', $command);

            return $command->getResult();

        } else if ($command instanceof CommandSet) {

            foreach ($command as $c) {

                if ($c->getClient() && $c->getClient() !== $this) {
                    throw new Command\CommandSetException(
                        'Attempting to run a mixed-Client CommandSet from a ' .
                        'Client context.  Run the set using CommandSet::execute() '
                    );
                }

                $c->setClient($this);
            }

            return $command->execute();

        } else {
            throw new ServiceException('Invalid command sent to ' . __METHOD__);
        }
    }

    /**
     * Get the base service endpoint URL with configuration options injected
     * into the configuration setting.
     *
     * @param bool $inject (optional) Set to FALSE to get the raw base_url
     *
     * @return string
     */
    public function getBaseUrl($inject = true)
    {
        return $inject ? $this->inject($this->config->get('base_url')) : $this->config->get('base_url');
    }

    /**
     * Set the base service endpoint URL
     *
     * @param string $url The base service endpoint URL of the webservice
     *
     * @return Client
     */
    public function setBaseUrl($url)
    {
        $this->config->set('base_url', $url);
        // Store the injected base_url
        $this->baseUrl = Url::factory($this->getBaseUrl());

        return $this;
    }

    /**
     * Inject configuration values into a formatted string with {{param}} as a
     * parameter delimiter (replace param with the configuration value name)
     *
     * @param string $string String to inject config values into
     *
     * @return string
     */
    public function inject($string)
    {
        return Guzzle::inject($string, $this->config);
    }

    /**
     * Get the service description of the client
     *
     * @return ServiceDescription|NullObject
     */
    public function getService()
    {
        return $this->serviceDescription ?: new NullObject();
    }

    /**
     * Set the name of your application and application version that will be
     * appended to the User-Agent header of all reqeusts.
     *
     * @param string $appName Name of your application
     * @param string $version Version number of your application
     *
     * @return Client
     */
    public function setUserApplication($appName, $version)
    {
        $this->userApplication = $appName . '/' . ($version ?: '1.0');

        return $this;
    }

    /**
     * Create a GET request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     * @return Request
     */
    public function get($uri = null, $inject = null)
    {
        return $this->createRequest('GET', $uri, $inject);
    }

    /**
     * Create a HEAD request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     * @return Request
     */
    public function head($uri = null, $inject = null)
    {
        return $this->createRequest('HEAD', $uri, $inject);
    }

    /**
     * Create a DELETE request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     *
     * @return Request
     */
    public function delete($uri = null, $inject = null)
    {
        return $this->createRequest('DELETE', $uri, $inject);
    }

    /**
     * Create a PUT request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     * @return EntityEnclosingRequest
     */
    public function put($uri = null, $inject = null)
    {
        return $this->createRequest('PUT', $uri, $inject);
    }

    /**
     * Create a POST request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an absolute path to
     *      override the base path, or a relative path to append it.
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     * @return EntityEnclosingRequest
     */
    public function post($uri = null, $inject = null)
    {
        return $this->createRequest('POST', $uri, $inject);
    }

    /**
     * Hook method to initialize the client
     *
     * @return void
     */
    protected function init()
    {
        return;
    }
}