<?php

namespace Guzzle\Service\Builder;

use Guzzle\Common\Exception\RuntimeException;
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
                    throw new ServiceNotFoundException(
                        "{$name} is trying to extend a non-existent service: {$service['extends']}"
                    );
                }

                $extended = &$services[$service['extends']];
                // Use the correct class attribute
                if (empty($service['class'])) {
                    $service['class'] = isset($extended['class']) ? $extended['class'] : '';
                }
                $extendsParams = isset($extended['params']) ? $extended['params'] : false;
                if ($extendsParams) {
                    $service['params'] = $service['params'] + $extendsParams;
                }
            }

            // Overwrite default values with global parameter values
            if (!empty($options)) {
                $service['params'] = $options + $service['params'];
            }

            $service['class'] = isset($service['class']) ? $service['class'] : '';
        }

        return new $class($services);
    }
}
