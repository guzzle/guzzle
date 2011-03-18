<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Cache\CacheAdapterInterface;
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
     * @var Collection Parameter object holding configuration data
     */
    private $config;

    /**
     * @var Url Base URL of the client
     */
    private $baseUrl;

    /**
     * @var Url Cached injected base URL
     */
    private $injectedBaseUrl;

    /**
     * Basic factory method to create a new client.  Extend this method in
     * subclasses to build more complex clients.
     *
     * @param array|Collection $config (optiona) Configuartion data
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings
     * @param int $cacheTtl (optional) How long to cache data
     *
     * @return Client
     */
    public static function factory($config, CacheAdapterInterface $cache = null, $ttl = 86400)
    {
        return new self($config['base_url'], $config);
    }
    
    /**
     * Client constructor
     *
     * @param string $baseUrl Base URL used to interact with a web service
     * @param array|Collection $config (optional) Configuration settings
     * @throws ServiceException
     */
    public function __construct($baseUrl, $config = null)
    {
        $this->setBaseUrl($baseUrl);
        if ($config) {
            $this->setConfig($config);
        }
    }

    /**
     * Set the configuration object to use with the client
     *
     * @param array|Collection|string $config Parameters that define how the
     *      client behaves and connects to a webservice.  Pass an array or a
     *      Collection object.
     *
     * @return Client
     */
    public final function setConfig($config)
    {
        // Set the configuration object
        if ($config instanceof Collection) {
            $this->config = $config;
        } else if (is_array($config)) {
            $this->config = new Collection($config);
        } else {
            throw new \InvalidArgumentException(
                'Config must be an array or Collection'
            );
        }

        return $this;
    }

    /**
     * Get a configuration setting or all of the configuration settings
     *
     * @param bool|string $key Configuration value to retrieve.  Set to FALSE
     *      to retrieve all values of the client.  The object return can be
     *      modified, and modifications will affect the client's config.
     *
     * @return mixed|Collection
     */
    public final function getConfig($key = false)
    {
        if (!$this->config) {
            $this->config = new Collection();
        }

        return $key ? $this->config->get($key) : $this->config;
    }

    /**
     * Inject configuration values into a formatted string with {{param}} as a
     * parameter delimiter (replace param with the configuration value name)
     *
     * @param string $string String to inject config values into
     *
     * @return string
     */
    public final function inject($string)
    {
        return Guzzle::inject($string, $this->getConfig());
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
                $url = clone $this->injectedBaseUrl;
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
        foreach ($this->getConfig()->getAll(array('/^curl\..+/')) as $key => $value) {
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
                'No command factory has been set on the client.  A command ' .
                'factory is usually set on a client by a builder object.'
            );
        }

        $command = $this->commandFactory->buildCommand($name, $args);
        $command->setClient($this);
        $this->getEventManager()->notify('command.create', $command);

        return $command;
    }

    /**
     * Set the command factory that will create command objects by name
     *
     * @param CommandFactoryInterface $factory Factory to create commands
     *
     * @return Client
     */
    public final function setCommandFactory(CommandFactoryInterface $commandFactory)
    {
        $this->commandFactory = $commandFactory;

        return $this;
    }

    /**
     * Execute a command and return the response
     *
     * @param CommandInterface|CommandSet $command The command or set to execute
     *
     * @return mixed Returns the result of the executed command's
     *       {@see CommandInterface::getResult} method if a CommandInterface is
     *       passed, or the CommandSet itself if a CommandSet is passed
     * @throws InvalidArgumentException
     * @throws Command\CommandSetException if a set contains commands associated
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
     * @param bool $inject (optional) Set to FALSE to get the raw base URL
     *
     * @return string
     * @throws ServiceException if a base URL has not been set
     */
    public function getBaseUrl($inject = true)
    {
        if (!$this->baseUrl) {
            throw new ServiceException('A base URL has not been set');
        }

        return $inject ? $this->inject((string) $this->baseUrl) : $this->baseUrl;
    }

    /**
     * Set the base service endpoint URL
     *
     * @param string $url The base service endpoint URL of the webservice
     *
     * @return Client
     */
    public final function setBaseUrl($url)
    {
        $this->baseUrl = $url;
        $this->injectedBaseUrl = Url::factory($this->inject($url));

        return $this;
    }

    /**
     * Set the service description of the client
     *
     * @param ServiceDescription $description Service description that describes
     *      all of the commands and information of the client
     *
     * @return Client
     */
    public final function setService(ServiceDescription $service)
    {
        $this->serviceDescription = $service;

        return $this;
    }

    /**
     * Get the service description of the client
     *
     * @return ServiceDescription|NullObject
     */
    public final function getService()
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
    public final function setUserApplication($appName, $version)
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
    public final function get($uri = null, $inject = null)
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
    public final function head($uri = null, $inject = null)
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
    public final function delete($uri = null, $inject = null)
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
    public final function put($uri = null, $inject = null)
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
    public final function post($uri = null, $inject = null)
    {
        return $this->createRequest('POST', $uri, $inject);
    }

    /**
     * Create an OPTIONS request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or relative path to append
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     * @return Request
     */
    public final function options($uri = null, $inject = null)
    {
        return $this->createRequest('OPTIONS', $uri, $inject);
    }
}