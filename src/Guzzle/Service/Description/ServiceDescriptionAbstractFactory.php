<?php

namespace Guzzle\Service\Description;

use Guzzle\Service\AbstractFactory;
use Guzzle\Service\Exception\DescriptionBuilderException;

/**
 * Abstract factory used to build service descriptions
 */
class ServiceDescriptionAbstractFactory extends AbstractFactory implements ServiceDescriptionFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    protected function getCacheTtlKey($config)
    {
        return 'cache.description.ttl';
    }

    /**
     * {@inheritdoc}
     */
    protected function throwException($message = '')
    {
        throw new DescriptionBuilderException($message ?: 'Unable to load service description due to unknown file extension');
    }

    /**
     * {@inheritdoc}
     */
    protected function getFactory($config)
    {
        if (is_array($config)) {
            return new ArrayDescriptionBuilder();
        } else {
            $ext = pathinfo($config, PATHINFO_EXTENSION);
            if ($ext == 'js' || $ext == 'json') {
                return new JsonDescriptionBuilder();
            } elseif ($ext == 'xml') {
                return new XmlDescriptionBuilder();
            }
        }
    }
}
