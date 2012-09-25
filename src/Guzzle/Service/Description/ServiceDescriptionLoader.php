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
                    foreach ((array) $op['extends'] as $extendedCommand) {
                        if (empty($operations[$extendedCommand])) {
                            throw new DescriptionBuilderException("{$name} extends missing operation {$extendedCommand}");
                        }
                        $toArray = $operations[$extendedCommand];
                        $resolved = empty($resolved)
                            ? $toArray['parameters']
                            : array_merge($resolved, $toArray['parameters']);
                        $op = array_merge($toArray, $op);
                    }
                    $op['parameters'] = $original ? array_merge($resolved, $original) : $resolved;
                }
                // Use the default class
                $op['class'] = isset($op['class']) ? $op['class'] : Operation::DEFAULT_COMMAND_CLASS;
                if (!isset($op['parameters'])) {
                    $op['parameters'] = array();
                }
                $operations[$name] = $op;
            }
        }

        return new ServiceDescription(array(
            'operations'  => $operations,
            'models'      => isset($config['models']) ? $config['models'] : null,
            'apiVersion'  => isset($config['apiVersion']) ? $config['apiVersion'] : null,
            'description' => isset($config['description']) ? $config['description'] : null
        ));
    }
}
