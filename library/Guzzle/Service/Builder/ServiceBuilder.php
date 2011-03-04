<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Builder;

use Guzzle\Common\CacheAdapter\CacheAdapterInterface;
use Guzzle\Service\ServiceException;

/**
 * Service builder to generate service builders and service clients from
 * configuration settings
 *
 * @author  michael@guzzlephp.org
 */
class ServiceBuilder
{
    /**
     * @var array Service builder configuration data
     */
    protected $serviceBuilderConfig = array();

    /**
     * @var array Instantiated service builders
     */
    protected $serviceBuilders = array();

    /**
     * @var array Instantiated client objects
     */
    protected $clients = array();

    /**
     * @var CacheAdapterInterface Cache adapter to use for Service caching
     */
    protected $cache;

    /**
     * @var int Cache entry TTL
     */
    protected $cacheTtl;

    /**
     * Create a new ServiceBuilder using an XML configuration file to configure
     * the registered ServiceBuilder builder objects
     *
     * @param string $filename Full path to the XML configuration file
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings loaded from the
     *      XML and to cache dynamically built services.
     * @param int $cacheTtl (optional) How long to cache items in the cache
     *      adapter (defaults to 24 hours).
     *
     * @return ServiceBuilder
     * @throws ServiceException if the file cannot be openend
     */
    public static function factory($filename, CacheAdapterInterface $cacheAdapter = null, $cacheTtl = 86400)
    {
        // Compute the cache key for this service and check if it exists in cache
        $key = 'guz_service_' . md5($filename);
        $cached = ($cacheAdapter) ? $cacheAdapter->fetch($key) : false;

        if ($cached) {

            // Load the config from cache
            $config = unserialize($cached);

        } else {

            // Build the service config from the XML file if the file exists
            if (!is_file($filename)) {
                throw new ServiceException('Unable to open service configuration file ' . $filename);
            }

            $config = array();
            $xml = new \SimpleXMLElement($filename, null, true);

            // Create a client entry for each client in the XML file
            foreach ($xml->clients->client as $client) {

                $row = array();
                $name = (string) $client->attributes()->name;
                $builder = (string) $client->attributes()->builder;
                $class = (string) $client->attributes()->class;

                // Check if this client builder extends another client
                if ($extends = (string) $client->attributes()->extends) {
                    // Make sure that the service it's extending has been defined
                    if (!isset($config[$extends])) {
                        throw new ServiceException($name . ' is trying to extend a non-existent or not yet defined service: ' . $extends);
                    }

                    $builder = $builder ?: $config[$extends]['builder'];
                    $class = $class ?: $config[$extends]['class'];
                    $row = $config[$extends]['params'];
                }

                // Add attributes to the row's parameters
                foreach ($client->param as $param) {
                    $row[(string) $param->attributes()->name] = (string) $param->attributes()->value;
                }

                // Add this client builder
                $config[$name] = array(
                    'builder' => $builder,
                    'class' => $class,
                    'params' => $row
                );
            }

            if ($cacheAdapter) {
                $cacheAdapter->save($key, serialize($config), $cacheTtl);
            }
        }

        $builder = new self($config);
        if ($cacheAdapter) {
            // Always share the cache
            $builder->setCache($cacheAdapter, $cacheTtl);
        }

        return $builder;
    }

    /**
     * Construct a new service builder
     *
     * @param array $serviceBuilderConfig Service configuration settings:
     *      name => Name of the service
     *      class => Builder class used to create clients using dot notation (Guzzle.Service.Aws.S3builder or Guzzle.Service.Builder.DefaultBuilder)
     *      params => array of key value pair configuration settings for the builder
     */
    public function __construct(array $serviceBuilderConfig)
    {
        $this->serviceBuilderConfig = $serviceBuilderConfig;
    }

    /**
     * Set the CacheAdapter to pass to generated builders which will allow the
     * builders to access the CacheAdapter.  This is helpul for speeding up
     * the process of parsing and loading dynamically generated clients.
     *
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings loaded from the
     *      XML and to cache dynamically built services.
     * @param int $cacheTtl (optional) How long to cache items in the cache
     *      adapter (defaults to 24 hours).
     *
     * @return ServiceBuilder
     */
    public function setCache(CacheAdapterInterface $cacheAdapter, $cacheTtl = 86400)
    {
        $this->cache = $cacheAdapter;
        $this->cacheTtl = $cacheTtl ?: 86400;

        return $this;
    }

    /**
     * Get a registered service builder by name
     *
     * @param string $name Name of the registered service builder to retrieve
     * @param bool $throwAway (optional) Set to TRUE to not store the builder
     *      for later retrieval on the ServiceBuilder
     *
     * @return AbstractBuilder
     * @throws ServiceException if no builder is registered by the requested name
     * @throws ServiceException if no client attribute is set when using a DefaultBuilder
     */
    public function getBuilder($name, $throwAway = false)
    {
        if (!$throwAway && isset($this->serviceBuilders[$name])) {
            return $this->serviceBuilders[$name];
        }

        if (!isset($this->serviceBuilderConfig[$name])) {
            throw new ServiceException('No service builder is registered as ' . $name);
        }

        // Use the DefaultBuilder if no builder was specified
        if (!isset($this->serviceBuilderConfig[$name]['builder'])) {
            $this->serviceBuilderConfig[$name]['builder'] = 'Guzzle.Service.Builder.DefaultBuilder';
        }

        $class = str_replace('.', '\\', $this->serviceBuilderConfig[$name]['builder']);
        $builder = new $class($this->serviceBuilderConfig[$name]['params'], $name);
        if ($this->cache) {
            $builder->setCache($this->cache, $this->cacheTtl);
        }

        if ($class == 'Guzzle\\Service\\Builder\\DefaultBuilder') {
            if (!isset($this->serviceBuilderConfig[$name]['class'])) {
                throw new ServiceException('A class attribute must be present when using Guzzle\\Service\\Builder\\DefaultBuilder');
            }
            $builder->setClass($this->serviceBuilderConfig[$name]['class']);
        }

        if (!$throwAway) {
            $this->serviceBuilders[$name] = $builder;
        }

        return $builder;
    }

    /**
     * Get a client using a registered builder
     *
     * @param $name Name of the registered client to retrieve
     * @param bool $throwAway (optional) Set to TRUE to not store the client
     *     for later retrieval from the ServiceBuilder
     *
     * @return Client
     */
    public function getClient($name, $throwAway = false)
    {
        if (!$throwAway && isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        $client = $this->getBuilder($name, $throwAway)->build();

        if (!$throwAway) {
            $this->clients[$name] = $client;
        }

        return $client;
    }
}