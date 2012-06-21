<?php

namespace Guzzle\Service\Builder;

use Guzzle\Service\AbstractFactory;
use Guzzle\Service\Exception\ServiceBuilderException;

/**
 * Abstract factory used to build service builders
 */
class ServiceBuilderAbstractFactory extends AbstractFactory implements ServiceBuilderFactoryInterface
{
    const ARRAY_FACTORY = 'ArrayServiceBuilderFactory';
    const JSON_FACTORY = 'JsonServiceBuilderFactory';
    const XML_FACTORY = 'XmlServiceBuilderFactory';

    /**
     * {@inheritdoc}
     */
    protected function getCacheTtlKey($config)
    {
        return 'cache.builder.ttl';
    }

    /**
     * {@inheritdoc}
     */
    protected function throwException($message = '')
    {
        throw new ServiceBuilderException($message ?: 'Unable to build service builder');
    }

    /**
     * {@inheritdoc}
     */
    protected function getClassName($config)
    {
        if (is_array($config)) {
            $class = self::ARRAY_FACTORY;
        } elseif (is_string($config)) {
            $ext = pathinfo($config, PATHINFO_EXTENSION);
            if ($ext == 'js' || $ext == 'json') {
                $class = self::JSON_FACTORY;
            } elseif ($ext == 'xml') {
                $class = self::XML_FACTORY;
            } else {
                $this->throwException(
                    "Unable to determine which factory to use based on the file extension of {$config}."
                    . " Valid file extensions are: .js, .json, .xml"
                );
            }
        } elseif ($config instanceof \SimpleXMLElement) {
            $class = self::XML_FACTORY;
        } else {
            $this->throwException('Must pass a file name, array, or SimpleXMLElement');
        }

        return __NAMESPACE__ . '\\' . $class;
    }
}
