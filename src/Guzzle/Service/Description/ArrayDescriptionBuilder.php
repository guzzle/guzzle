<?php

namespace Guzzle\Service\Description;

use Guzzle\Service\Inspector;
use Guzzle\Service\Exception\DescriptionBuilderException;

/**
 * Build service descriptions using an array of configuration data
 */
class ArrayDescriptionBuilder implements DescriptionBuilderInterface
{
    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        if (!empty($config['types'])) {
            foreach ($config['types'] as $name => $type) {
                $default = array();
                if (!isset($type['class'])) {
                    throw new DescriptionBuilderException('Custom types require a class attribute');
                }
                foreach ($type as $key => $value) {
                    if ($key != 'name' && $key != 'class') {
                        $default[$key] = $value;
                    }
                }
                Inspector::getInstance()->registerConstraint($name, $type['class'], $default);
            }
        }

        $commands = array();
        if (!empty($config['commands'])) {
            foreach ($config['commands'] as $name => $command) {
                $name = $command['name'] = isset($command['name']) ? $command['name'] : $name;
                // Extend other commands
                if (!empty($command['extends'])) {
                    foreach ((array) $command['extends'] as $extendedCommand) {
                        if (empty($commands[$extendedCommand])) {
                            throw new DescriptionBuilderException("{$name} extends missing command {$extendedCommand}");
                        }
                        $toArray = $commands[$extendedCommand]->toArray();
                        $params = empty($command['params']) ? $toArray['params'] : array_merge($command['params'], $toArray['params']);
                        $command = array_merge($toArray, $command);
                        $command['params'] = $params;
                    }
                }
                // Use the default class
                $command['class'] = isset($command['class']) ? str_replace('.', '\\', $command['class']) : ServiceDescription::DEFAULT_COMMAND_CLASS;
                $commands[$name] = new ApiCommand($command);
            }
        }

        return new ServiceDescription($commands);
    }
}
