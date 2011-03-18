<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Mock;

use Guzzle\Common\Cache\CacheAdapterInterface;
use Guzzle\Service\Builder\DefaultBuilder;
use Guzzle\Service\Client;

/**
 * Mock Guzzle Service
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockClient extends Client
{
    /**
     * Factory method to create a new mock client
     *
     * @param array|Collection $config Configuration data. Array keys:
     *    base_url - Base URL of web service
     *    api_version - API version
     *    scheme - URI scheme: http or https
     *  * username - API username
     *  * password - API password
     *  * subdomain - Unfuddle account subdomain
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings
     * @param int $cacheTtl (optional) How long to cache data
     *
     * @return MockClient
     */
    public static function factory($config, CacheAdapterInterface $cache = null, $ttl = 86400)
    {
        $config = DefaultBuilder::prepareConfig($config, array(
            'base_url' => '{{scheme}}://127.0.0.1:8124/{{api_version}}/{{subdomain}}',
            'scheme' => 'http',
            'api_version' => 'v1'
        ), array('username', 'password', 'subdomain'));
        $client = new self($config->get('base_url'), $config);

        return DefaultBuilder::build($client, $cache, $ttl);
    }
}