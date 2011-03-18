<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Builder;

use Guzzle\Common\Cache\CacheAdapterInterface;
use Guzzle\Common\Collection;
use Guzzle\Service\ServiceException;
use Guzzle\Service\ServiceDescription;
use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Service\DescriptionBuilder\ConcreteDescriptionBuilder;
use Guzzle\Service\Client;

/**
 * Default service client builder
 *
 * @author  michael@guzzlephp.org
 */
class DefaultBuilder
{
    /**
     * Validate and prepare configuration parameters for a client
     *
     * @param array $config Configuration values to apply.  A 'base_url' array
     *      key is required, specifying the base URL of the web service.
     * @param array $defaults (optional) Default parameters
     * @param array $required (optional) Required parameter names
     *
     * @return Collection
     * @throws InvalidArgumentException if a base_url is not specified or missing argument
     */
    public static function prepareConfig(array $config = null, $defaults = null, $required = null)
    {
        $collection = new Collection((array) $defaults);
        foreach ((array) $config as $key => $value) {
            $collection->set($key, $value);
        }
        foreach ((array) $required as $key) {
            if (!$collection->hasKey($key)) {
                throw new \InvalidArgumentException(
                    "Client config must contain a '{$key}' key"
                );
            }
        }

        // Make sure that the service has a base_url specified
        if (!$collection->get('base_url')) {
            throw new \InvalidArgumentException(
                'No base_url is set in the builder config'
            );
        }

        return $collection;
    }

    /**
     * Build the client
     *
     * @param Client $client Client object to add a command factory and description
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings
     * @param int $cacheTtl (optional) How long to cache data
     *
     * @return Client
     * @throws ServiceException if the class of the client is not set
     * @throws ServiceException if the class set cannot be found
     */
    public static function build(Client $client, CacheAdapterInterface $cache = null, $cacheTtl = 86400)
    {
        $class = get_class($client);
        $serviceDescription = false;
        $key = 'guzzle_' . str_replace('\\', '_', strtolower($class));
        
        if ($cache) {
            $serviceDescription = $cache->fetch($key);
            if ($serviceDescription) {
                if (!is_object($serviceDescription)) {
                    $serviceDescription = unserialize($serviceDescription);
                }
                $client->getConfig()->set('_service_from_cache', $key);
            }
        }

        if (!$serviceDescription) {          
            $builder = new ConcreteDescriptionBuilder($class, $client->getConfig()->get('base_url'));
            $serviceDescription = $builder->build();
            // If the description was built and a cache is set, cache it
            if ($cache) {
                $cache->save($key, serialize($serviceDescription), $cacheTtl);
            }
        }

        $client->setCommandFactory(new ConcreteCommandFactory($serviceDescription))
               ->setService($serviceDescription);

        return $client;
    }
}