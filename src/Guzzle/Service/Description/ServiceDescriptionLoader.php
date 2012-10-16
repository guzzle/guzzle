<?php

namespace Guzzle\Service\Description;

use Guzzle\Service\AbstractConfigLoader;
use Guzzle\Service\Exception\DescriptionBuilderException;

/**
 * Loader for service descriptions
 */
class ServiceDescriptionLoader extends AbstractConfigLoader
{
    /**
     * {@inheritdoc}
     */
    protected function build($config, array $options)
    {
        $operations = array();
        if (!empty($config['operations'])) {
            foreach ($config['operations'] as $name => $op) {
                $name = $op['name'] = isset($op['name']) ? $op['name'] : $name;
                // Extend other operations
                if (!empty($op['extends'])) {
                    $original = empty($op['parameters']) ? false: $op['parameters'];
                    $resolved = array();
                    $hasClass = !empty($op['class']);
                    foreach ((array) $op['extends'] as $extendedCommand) {
                        if (empty($operations[$extendedCommand])) {
                            throw new DescriptionBuilderException("{$name} extends missing operation {$extendedCommand}");
                        }
                        $toArray = $operations[$extendedCommand];
                        $resolved = empty($resolved)
                            ? $toArray['parameters']
                            : array_merge($resolved, $toArray['parameters']);

                        $op = array_merge($toArray, $op);
                        if (!$hasClass && isset($toArray['class'])) {
                            $op['class'] = $toArray['class'];
                        }
                    }
                    $op['parameters'] = $original ? array_merge($resolved, $original) : $resolved;
                }
                if (!isset($op['parameters'])) {
                    $op['parameters'] = array();
                }
                $operations[$name] = $op;
            }
        }

        return new ServiceDescription(array(
            'apiVersion'  => isset($config['apiVersion']) ? $config['apiVersion'] : null,
            'baseUrl'     => isset($config['baseUrl']) ? $config['baseUrl'] : null,
            'description' => isset($config['description']) ? $config['description'] : null,
            'operations'  => $operations,
            'models'      => isset($config['models']) ? $config['models'] : null
        ));
    }
}
