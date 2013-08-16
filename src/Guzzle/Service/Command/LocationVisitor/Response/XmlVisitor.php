<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\CommandInterface;
use SimpleXMLElement;

/**
 * Location visitor used to marshal XML response data into a formatted array
 */
class XmlVisitor extends AbstractResponseVisitor
{
    /**
     * XML document being visited.
     *
     * @var SimpleXMLElement
     */
    protected $xml;

    public function before(CommandInterface $command, array &$result)
    {
        // Retrieve XML structure for processing
        $this->xml = $command->getResponse()->xml();
    }

    public function visit(
        CommandInterface $command,
        Response $response,
        Parameter $param,
        &$value,
        $context = null
    )
    {
        $sentAs = $param->getWireName();
        $name = $param->getName();
        if (isset($this->xml->{$sentAs})) {
            $node = $this->xml->{$sentAs};
            $value[$name] = $this->recursiveProcess($param, $node);
        }

        // Handle additional, undefined properties
        $additional = $param->getAdditionalProperties();
        if ($additional instanceof Parameter) {
            // Process all child elements according to the given schema
            foreach ($this->xml->children() as $node) {
                if ($node->getName() != $sentAs) {
                    $value[$name] = $this->recursiveProcess($additional, $node);
                }
            }
        } elseif ($additional === true) {
            // Blindly transform the XML into an array preserving as much data as possible
            $array = static::xmlToArray($this->xml);
            $value = array_merge($array, $value);
        }
    }

    /**
     * Recursively process a parameter while applying filters
     *
     * @param Parameter         $param API parameter being processed
     * @param \SimpleXMLElement $node  Node being processed
     * @return array
     */
    protected function recursiveProcess(Parameter $param, SimpleXMLElement $node)
    {
        $result = array();
        $type = $param->getType();

        if ($type == 'array') {
            // Cast to an array if the value was a string, but should be an array
            $items = $param->getItems();
            if ($itemName = $items->getWireName()) {
                // A collection of named, repeating nodes (i.e. <collection><foo></foo><foo></foo></collection>
                foreach ($node->{$itemName} as $child) {
                    $result[] = $this->recursiveProcess($items, $child);
                }
            } else {
                // A general collection of nodes
                foreach ($node as $child) {
                    $result[] = $this->recursiveProcess($items, $child);
                }
            }
        } elseif ($type == 'object') {
            $result = $this->processObject($param, $node);
        } else {
            // We are probably handling a flat data node (i.e. string or integer), so
            // let's check if it's childless, which indicates a node containing plain text.
            if ($node->children()->count() == 0) {
                // Retrieve text from node
                $result = (string)$node;
            }
        }

        // Filter out the value
        if (!empty($result)) {
            $result = $param->filter($result);
        }

        return $result;
    }

    /**
     * Process an object
     *
     * @param Parameter        $param API parameter being parsed
     * @param SimpleXMLElement $node  Value to process
     * @return array
     */
    protected function processObject(Parameter $param, SimpleXMLElement $node)
    {
        $result = $knownProps = array();

        // Handle known properties
        if ($properties = $param->getProperties()) {
            foreach ($properties as $property) {
                $name = $property->getName();
                $sentAs = $property->getWireName();
                $knownProps[$sentAs] = 1;

                if ($property->getData('xmlAttribute')) {
                    // Handle XML attributes
                    $result[$name] = $node->attributes()->{$sentAs};

                } elseif (isset($node->{$sentAs})) {
                    // Found a child node matching wire name
                    $childNode = $node->{$sentAs};
                    $result[$name] = $this->recursiveProcess($property, $childNode);
                }
            }
        }

        // Handle additional, undefined properties
        $additional = $param->getAdditionalProperties();
        if ($additional instanceof Parameter) {
            // Process all child elements according to the given schema
            foreach ($node->children() as $childNode) {
                $sentAs = $childNode->getName();
                if (!isset($knownProps[$sentAs])) {
                    $result[$sentAs] = $this->recursiveProcess($additional, $childNode);
                }
            }
        } elseif ($additional === true) {
            // Blindly transform the XML into an array preserving as much data as possible
            $array = static::xmlToArray($node);

            // Remove processed, aliased properties
            $array = array_diff_key($array, $knownProps);

            // Merge it together with the original result
            $result = array_merge($array, $result);
        }

        return $result;
    }

    public function after(CommandInterface $command)
    {
        // Free up memory
        $this->xml = null;
    }

    /**
     * @return SimpleXMLElement
     */
    public function getXml()
    {
        return $this->xml;
    }

    /**
     * @param SimpleXMLElement $xml
     */
    public function setXml(SimpleXMLElement $xml)
    {
        $this->xml = $xml;
    }

    /**
     * @param SimpleXMLElement $xml
     * @param int              $nesting
     * @return array
     */
    protected function xmlToArray(SimpleXMLElement $xml, $nesting = 0)
    {
        $result = array();
        $attributes = (array)$xml->attributes(null, true);
        $children = $xml->children(null, true);

        foreach ($children as $name => $child) {
            if ($sibling = $child->xpath('preceding-sibling::* | following-sibling::*')) {
                if (current($sibling)->getName() === $name) {
                    $result[$name][] = static::xmlToArray($child, $nesting + 1);
                    continue;
                }
            }
            $result[$name] = static::xmlToArray($child, $nesting + 1);
        }

        $text = trim((string)$xml);

        if (empty($text)) {
            $text = null;
        }

        if (!empty($attributes)) {
            if (!is_null($text)) {
                $result['value'] = $text;
                $result = array_merge($attributes, $result);
            }
        } else if (!is_null($text)) {
            $result = $text;
        }

        // Make sure we're always returning an array
        if (!is_array($result) && $nesting == 0) {
            $result = array($result);
        }

        return $result;
    }
}
