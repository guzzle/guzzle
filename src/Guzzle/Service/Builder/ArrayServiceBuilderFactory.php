<?php

namespace Guzzle\Service\Builder;

use Guzzle\Service\Exception\ServiceNotFoundException;

/**
 * Creates a ServiceBuilder using an array of data
 */
class ArrayServiceBuilderFactory implements ServiceBuilderFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        // A service builder class can be specified in the class field
        $class = !empty($config['class']) ? $config['class'] : __NAMESPACE__ . '\\ServiceBuilder';

        // Account for old style configs that do not have a services array
        $services = isset($config['services']) ? $config['services'] : $config;

        // Validate the configuration and handle extensions
        foreach ($services as $name => &$service) {

            $service['params'] = isset($service['params']) ? $service['params'] : array();

            // Check if this client builder extends another client
            if (!empty($service['extends'])) {

                // Make sure that the service it's extending has been defined
                if (!isset($services[$service['extends']])) {
                    throw new ServiceNotFoundException($name . ' is trying to extend a non-existent service: ' . $service['extends']);
                }

                $service['class'] = empty($service['class'])
                    ? $services[$service['extends']]['class'] : $service['class'];

                $extendsParams = isset($services[$service['extends']]['params']) ? $services[$service['extends']]['params'] : false;
                if ($extendsParams) {
                    $service['params'] = array_merge($extendsParams, $service['params']);
                }
            }

            // Overwrite default values with global parameter values
            if (!empty($options)) {
                $service['params'] = array_merge($service['params'], $options);
            }

            $service['class'] = !isset($service['class']) ? '' : str_replace('.', '\\', $service['class']);
        }

        return new $class($services);
    }
}
