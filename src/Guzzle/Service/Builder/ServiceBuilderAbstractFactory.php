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
     * @var array Pool of instantiated factories by name
     */
    protected $factories = array();

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
     * Get an array factory
     *
     * @return ArrayServiceBuilderFactory
     */
    public function getArrayFactory()
    {
        if (!isset($this->factories['array'])) {
            $this->factories['array'] = new ArrayServiceBuilderFactory();
        }

        return $this->factories['array'];
    }

    /**
     * Get a JSON factory
     *
     * @return JsonServiceBuilderFactory
     */
    public function getJsonFactory()
    {
        if (!isset($this->factories['json'])) {
            $this->factories['json'] = new JsonServiceBuilderFactory($this->getArrayFactory());
        }

        return $this->factories['json'];
    }

    /**
     * Get a XML factory
     *
     * @return XmlServiceBuilderFactory
     */
    public function getXmlFactory()
    {
        if (!isset($this->factories['xml'])) {
            $this->factories['xml'] = new XmlServiceBuilderFactory($this->getArrayFactory());
        }

        return $this->factories['xml'];
    }

    /**
     * {@inheritdoc}
     */
    protected function getFactory($config)
    {
        if (is_array($config)) {
            return $this->getArrayFactory();
        } elseif (is_string($config)) {
            $ext = pathinfo($config, PATHINFO_EXTENSION);
            if ($ext == 'js' || $ext == 'json') {
                return $this->getJsonFactory();
            } elseif ($ext == 'xml') {
                return $this->getXmlFactory();
            }
            return "Unable to determine which factory to use based on the file extension of {$config}."
                . " Valid file extensions are: .js, .json, .xml";

        } elseif ($config instanceof \SimpleXMLElement) {
            return $this->getXmlFactory();
        }

        return 'Must pass a file name, array, or SimpleXMLElement';
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
}
