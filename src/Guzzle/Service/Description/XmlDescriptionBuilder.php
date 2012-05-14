<?php

namespace Guzzle\Service\Description;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Service\Exception\DescriptionBuilderException;

/**
 * Build service descriptions using an XML document
 */
class XmlDescriptionBuilder implements DescriptionBuilderInterface
{
    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        return ServiceDescription::factory($this->parseXmlFile($config));
    }

    /**
     * Convert an XML file to an array of service data
     *
     * @param string $file XML filename to parse
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function parseXmlFile($file)
    {
        if (!file_exists($file)) {
            throw new DescriptionBuilderException('Unable to open ' . $file . ' for reading');
        }

        $xml = new \SimpleXMLElement($file, null, true);
        $config = array(
            'types' => array(),
            'commands' => array()
        );

        // Register any custom type definitions
        $types = $xml->types->type;
        if ($types) {
            foreach ($types as $type) {
                $attr = $type->attributes();
                $name = (string) $attr->name;
                $config['types'][$name] = array();
                foreach ($attr as $key => $value) {
                    $config['types'][$name][(string) $key] = (string) $value;
                }
            }
        }

        // Parse the commands in the XML doc
        $commands = $xml->commands->command;
        if ($commands) {
            foreach ($commands as $command) {
                $attr = $command->attributes();
                $name = (string) $attr->name;
                $config['commands'][$name] = array(
                    'params' => array()
                );
                foreach ($attr as $key => $value) {
                    $config['commands'][$name][(string) $key] = (string) $value;
                }
                $config['commands'][$name]['doc'] = (string) $command->doc;
                foreach ($command->param as $param) {
                    $attr = $param->attributes();
                    $paramName = (string) $attr['name'];
                    $config['commands'][$name]['params'][$paramName] = array();
                    foreach ($attr as $pk => $pv) {
                        $pv = (string) $pk == 'required' ? (string) $pv === 'true' : (string) $pv;
                        $config['commands'][$name]['params'][$paramName][(string) $pk] = (string) $pv;
                    }
                }
            }
        }

        // Handle XML includes
        $includes = $xml->includes->include;
        if ($includes) {
            foreach ($includes as $includeFile) {
                $path = (string) $includeFile->attributes()->path;
                if ($path[0] != DIRECTORY_SEPARATOR) {
                    $path = dirname($file) . DIRECTORY_SEPARATOR . $path;
                }
                $config = array_merge_recursive(self::parseXmlFile($path), $config);
            }
        }

        return $config;
    }
}
