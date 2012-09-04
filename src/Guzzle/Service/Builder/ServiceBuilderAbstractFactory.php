<?php

namespace Guzzle\Service\Builder;

use Guzzle\Service\AbstractFactory;
use Guzzle\Service\Exception\ServiceBuilderException;

/**
 * Abstract factory used to build service builders
 */
class ServiceBuilderAbstractFactory extends AbstractFactory implements ServiceBuilderFactoryInterface
{
    /**
     * Combines service builder configuration file arrays
     *
     * @param array $a Original data
     * @param array $b Data to merge in to the original data
     *
     * @return array
     */
    public static function combineConfigs(array $a, array $b)
    {
        $result = $b + $a;

        // Merge services using a recursive union of arrays
        if (isset($a['services']) && $b['services']) {

            // Get a union of the services of the two arrays
            $result['services'] = $b['services'] + $a['services'];

            // Merge each service in using a union of the two arrays
            foreach ($result['services'] as $name => &$service) {

                // By default, services completely override a previously defined service unless it extends itself
                if (isset($a['services'][$name]['extends'])
                    && isset($b['services'][$name]['extends'])
                    && $b['services'][$name]['extends'] == $name
                ) {
                    $service += $a['services'][$name];
                    // Use the `extends` attribute of the parent
                    $service['extends'] = $a['services'][$name]['extends'];
                    // Merge parameters using a union if both have paramters
                    if (isset($a['services'][$name]['params'])) {
                        $service['params'] += $a['services'][$name]['params'];
                    }
                }
            }
        }

        return $result;
    }

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
    protected function getFactory($config)
    {
        if (is_array($config)) {
            return new ArrayServiceBuilderFactory();
        } elseif (is_string($config)) {
            $ext = pathinfo($config, PATHINFO_EXTENSION);
            if ($ext == 'js' || $ext == 'json') {
                return new JsonServiceBuilderFactory();
            } elseif ($ext == 'xml') {
                return new XmlServiceBuilderFactory();
            }

            $this->throwException(
                "Unable to determine which factory to use based on the file extension of {$config}."
                . " Valid file extensions are: .js, .json, .xml"
            );

        } elseif ($config instanceof \SimpleXMLElement) {
            return new XmlServiceBuilderFactory();
        }

        return 'Must pass a file name, array, or SimpleXMLElement';
    }
}
