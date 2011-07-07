<?php

namespace Guzzle\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Common\Event\Subject;
use Guzzle\Common\NullObject;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Description\ServiceDescription;

/**
 * Client interface for executing commands on a web service.
 *
 * @author  michael@guzzlephp.org
 */
interface ClientInterface extends Subject
{
    /**
     * Basic factory method to create a new client.  Extend this method in
     * subclasses to build more complex clients.
     *
     * @param array|Collection $config (optiona) Configuartion data
     *
     * @return ClientInterface
     */
    static function factory($config);

    /**
     * Set the configuration object to use with the client
     *
     * @param array|Collection|string $config Parameters that define how the
     *      client behaves and connects to a webservice.  Pass an array or a
     *      Collection object.
     *
     * @return ClientInterface
     */
    function setConfig($config);

    /**
     * Get a configuration setting or all of the configuration settings
     *
     * @param bool|string $key Configuration value to retrieve.  Set to FALSE
     *      to retrieve all values of the client.  The object return can be
     *      modified, and modifications will affect the client's config.
     *
     * @return mixed|Collection
     */
    function getConfig($key = false);

    /**
     * Inject configuration values into a formatted string with {{param}} as a
     * parameter delimiter (replace param with the configuration value name)
     *
     * @param string $string String to inject config values into
     *
     * @return string
     */
    function inject($string);

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
    function createRequest($method = RequestInterface::GET, $uri = null, $inject = null);

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
    function prepareRequest(RequestInterface $request);

    /**
     * Get a command by name.  First, the client will see if it has a service
     * description and if the service description defines a command by the
     * supplied name.  If no dynamic command is found, the client will look for
     * a concrete command class exists matching the name supplied.  If neither
     * are found, an InvalidArgumentException is thrown.
     *
     * @param string $name Name of the command to retrieve
     * @param array $args (optional) Arguments to pass to the command
     *
     * @return CommandInterface
     * @throws InvalidArgumentException if no command can be found by name
     */
    function getCommand($name, array $args = array());

    /**
     * Execute a command and return the response
     *
     * @param CommandInterface|CommandSet $command The command or set to execute
     *
     * @return mixed Returns the result of the executed command's
     *       {@see CommandInterface::getResult} method if a CommandInterface is
     *       passed, or the CommandSet itself if a CommandSet is passed
     * @throws InvalidArgumentException if an invalid command is passed
     * @throws Command\CommandSetException if a set contains commands associated
     *      with other clients
     */
    function execute($command);

    /**
     * Get the base service endpoint URL with configuration options injected
     * into the configuration setting.
     *
     * @param bool $inject (optional) Set to FALSE to get the raw base URL
     *
     * @return string
     * @throws RuntimeException if a base URL has not been set
     */
    function getBaseUrl($inject = true);

    /**
     * Set the base service endpoint URL
     *
     * @param string $url The base service endpoint URL of the webservice
     *
     * @return ClientInterface
     */
    function setBaseUrl($url);

    /**
     * Set the service description of the client
     *
     * @param ServiceDescription $description Service description that describes
     *      all of the commands and information of the client
     *
     * @return ClientInterface
     */
    function setDescription(ServiceDescription $service);

    /**
     * Get the service description of the client
     *
     * @return ServiceDescription|NullObject
     */
    function getDescription();

    /**
     * Set the name of your application and application version that will be
     * appended to the User-Agent header of all reqeusts.
     *
     * @param string $appName Name of your application
     * @param string $version Version number of your application
     *
     * @return ClientInterface
     */
    function setUserApplication($appName, $version);

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
    function get($uri = null, $inject = null);

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
    function head($uri = null, $inject = null);

    /**
     * Create a DELETE request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection (optional) Parameters to replace from the uri
     *      {{}} injection points.
     *
     * @return Request
     */
    function delete($uri = null, $inject = null);

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
    function put($uri = null, $inject = null);

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
    function post($uri = null, $inject = null);

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
    function options($uri = null, $inject = null);
}