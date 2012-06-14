<?php

namespace Guzzle\Common\Cache;

use Zend\Cache\Storage\Adapter\AdapterInterface;

/**
 * Zend Framework 2 cache adapter
 *
 * @link http://packages.zendframework.com/docs/latest/manual/en/zend.cache.html
 */
class Zf2CacheAdapter extends AbstractCacheAdapter
{
    /**
     * @var array Associative array of default options per cache method name
     */
    protected $defaultOptions = array();

    /**
     * @param AdapterInterface $cache   Zend Framework 2 cache adapter
     * @param array            $options Hash of default options for each cache method.
     *                                  Can contain for 'contains', 'delete', 'fetch',
     *                                  and 'save'.  Each key must map to an
     *                                  associative array of options to merge into the
     *                                  options argument passed into each respective call.
     */
    public function __construct(AdapterInterface $cache, array $defaultOptions = array())
    {
        $this->cache = $cache;
        $this->defaultOptions = array_merge(array(
            'contains' => array(),
            'delete'   => array(),
            'fetch'    => array(),
            'save'     => array()
        ), $defaultOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id, array $options = null)
    {
        $options = $options
            ? array_merge($this->defaultOptions['contains'], $options)
            : $this->defaultOptions['contains'];

        return $this->cache->hasItem($id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id, array $options = null)
    {
        $options = $options
            ? array_merge($this->defaultOptions['delete'], $options)
            : $this->defaultOptions['delete'];

        return $this->cache->removeItem($id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($id, array $options = null)
    {
        $options = $options
            ? array_merge($this->defaultOptions['fetch'], $options)
            : $this->defaultOptions['fetch'];

        return $this->cache->getItem($id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $lifeTime = false, array $options = null)
    {
        return $this->cache->setItem($id, $data, array_merge($this->defaultOptions['save'], $options ?: array(), array(
            'ttl' => $lifeTime
        )));
    }
}
