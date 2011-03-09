<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Builder;

use Guzzle\Common\Cache\CacheAdapterInterface;
use Guzzle\Common\Collection;
use Guzzle\Common\Service\ServiceException;

/**
 * Abstract service client builder
 *
 * @author  michael@guzzlephp.org
 */
abstract class AbstractBuilder
{
    /**
     * @var string Name of the builder
     */
    protected $name;

    /**
     * @var Collection Configuration object that should hold all config settings
     */
    protected $config;

    /**
     * @var CacheAdapterInterface
     */
    protected $cache;

    /**
     * @var int Cache entry TTL
     */
    protected $cacheTtl;

    /**
     * Construct the builder
     *
     * @param array $config (optional) Configuration values to apply
     * @param string $name (optional) Name of the builder
     */
    public function __construct(array $config = null, $name = '')
    {
        $this->name = $name;
        $this->config = new Collection($config);
    }

    /**
     * Get the XML representation of the builder
     *
     * @return string
     */
    public function __toString()
    {
        $xml = '<service name="' . htmlspecialchars($this->getName()) . '" class="' . htmlspecialchars(str_replace('\\', '.', $this->getClass())) . '">' . "\n";

        foreach ($this->config as $key => $value) {
            $xml .= '    <param name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '" />' . "\n";
        }

        return $xml . '</service>';
    }

    /**
     * Set the CacheAdapter to use for the builder
     *
     * @param CacheAdapterInterface $cacheAdapter (optional) Pass a cache
     *      adapter to cache the service configuration settings loaded from the
     *      XML and to cache dynamically built services.
     * @param int $cacheTtl (optional) How long to cache items in the cache
     *      adapter (defaults to 24 hours).
     *
     * @return AbstractBuilder
     */
    public function setCache(CacheAdapterInterface $cacheAdapter, $cacheTtl = 86400)
    {
        $this->cache = $cacheAdapter;
        $this->cacheTtl = $cacheTtl ?: 86400;

        return $this;
    }

    /**
     * Build the client
     *
     * @return Client
     */
    abstract public function build();

    /**
     * Get the class name of the client that will be built by the builder
     *
     * @return string
     */
    abstract public function getClass();
    
    /**
     * Get the name of the builder
     * 
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the name of the builder
     *
     * @param string $name Name of the builder
     *
     * @return AbstractBuilder
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }
}