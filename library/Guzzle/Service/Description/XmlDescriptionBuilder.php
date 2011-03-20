<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

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

            $attr = $command->attributes();

            // Create a new command using the parsed XML
            $commands[] = new ApiCommand(array(
                'name' => (string) $attr->name,
                'doc' => (string) $command->doc,
                'method' => (string) $attr->method,
                'path' => (string) $attr->path,
                'min_args' => (int) (string) $attr->min_args,
                'can_batch' => (string) $attr->can_batch == 'false' ? false : true,
                'class' => (string) $attr->class ?: ServiceDescription::DEFAULT_COMMAND_CLASS,
                'args' => $args
            ));
        }

        return new ServiceDescription($commands);
    }
}