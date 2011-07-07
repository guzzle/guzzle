<?php

namespace Guzzle\Service\Description;

use Guzzle\Common\Inflector;
use Guzzle\Common\Inspector;

/**
 * Build service descriptions using an XML document
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class XmlDescriptionBuilder implements DescriptionBuilderInterface
{
    /**
     * @var string XML string
     */
    private $xml;

    /**
     * Create a new XmlDescriptionBuilder
     *
     * @param string $xml XML string or the full path of an XML file
     *
     * @throws InvalidArgumentException if the file cannot be opened
     */
    public function __construct($xml)
    {
        // Check if an XML string was passed or a filename
        if (strpos($xml, '<?xml') !== false) {
            $this->xml = $xml;
        } else {
            if (!is_readable($xml)) {
                throw new \InvalidArgumentException('Unable to open ' . $xml . ' for reading');
            }
            $this->xml = file_get_contents($xml);
        }
    }

    /**
     * Builds a new ServiceDescription object
     *
     * @return ServiceDescription
     */
    public function build()
    {
        $xml = new \SimpleXMLElement($this->xml);

        // Register any custom type definitions
        if ($xml->types) {
            foreach ($xml->types->type as $type) {
                $attr = $type->attributes();
                $name = (string) $attr->name;
                $class = (string) $attr->class;
                if ($name && $class) {
                    Inspector::getInstance()->registerFilter($name, $class, (string) $attr->default);
                }
            }
        }

        $commands = array();

        // Parse the commands in the XML doc
        foreach ($xml->commands->command as $command) {
            $args = array();
            $parentData = array();
            $parentArgs = array();
            $attr = $command->attributes();
            $data = array(
                'name' => (string) $attr->name
            );
            if ($v = (string) $command->doc) {
                $data['doc'] = $v;
            }
            if ($v = (string) $attr->method) {
                $data['method'] = $v;
            }
            if ($v = (string) $attr->path) {
                $data['path'] = $v;
            }
            if ($v = (int) (string) $attr->min_args) {
                $data['min_args'] = $v;
            }
            if ($v = (string) $attr->can_batch) {
                $data['can_batch'] = $v == 'false' ? false : true;
            }
            if ($v = (string) $attr->class) {
                $data['class'] = $v;
            }

            $extends = (string) $attr->extends;
            if ($extends) {
                $match = false;
                foreach ($commands as $cmd) {
                    if ($cmd->getName() == $extends) {
                        $match = $cmd;
                    }
                }
                if (!$match) {
                    throw new \RuntimeException((string) $attr->name
                        . 'is trying to extend non-existent command ' . $extends);
                } else {
                    $parentArgs = $match->getArgs();
                    $parentData = array(
                        'name' => $match->getName(),
                        'doc' => $match->getDoc(),
                        'method' => $match->getMethod(),
                        'path' => $match->getPath(),
                        'min_args' => $match->getMinArgs(),
                        'can_batch' => $match->canBatch(),
                        'class' => $match->getConcreteClass()
                    );
                }
            }

            // Add the arguments to the command
            foreach ($command->param as $arg) {
                $row = array();
                // Add each attribute to the argument
                foreach ($arg->attributes() as $key => $value) {
                    $key = (string) $key;
                    $value = (string) $value;
                    // Convert Boolean strings to Booleans
                    if ($key == 'required') {
                        $value = $value === 'true' ? true : false;
                    }
                    $row[$key] = $value;
                }
                $args[(string) $arg->attributes()->name] = $row;
            }

            $data = array_merge($parentData, $data);
            $data['args'] = array_merge($parentArgs, $args);
            if (!isset($data['class'])) {
                $data['class'] = ServiceDescription::DEFAULT_COMMAND_CLASS;
            } else {
                $data['class'] = str_replace('.', '\\', $data['class']);
            }

            // Create a new command using the parsed XML
            $commands[] = new ApiCommand($data);
        }

        return new ServiceDescription($commands);
    }
}