<?php

namespace Guzzle\Service\Command\LocationVisitor\Request;

use Guzzle\Common\Exception\RuntimeException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Description\Parameter;

/**
 * Location visitor used to serialize XML bodies
 */
class XmlVisitor extends AbstractRequestVisitor
{
    /**
     * @var \SplObjectStorage Data object for persisting XML data
     */
    protected $data;

    /**
     * @var bool Content-Type header added when XML is found
     */
    protected $contentType = 'application/xml';

    /**
     * This visitor uses an {@see \SplObjectStorage} to associate XML data with commands
     */
    public function __construct()
    {
        $this->data = new \SplObjectStorage();
    }

    /**
     * Change the content-type header that is added when XML is found
     *
     * @param string $header Header to set when XML is found
     *
     * @return self
     */
    public function setContentTypeHeader($header)
    {
        $this->contentType = $header;

        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws RuntimeException
     */
    public function visit(CommandInterface $command, RequestInterface $request, Parameter $param, $value)
    {
        static $defaultRoot = array('name' => 'Request');

        if (isset($this->data[$command])) {
            $xml = $this->data[$command];
        } elseif ($parent = $param->getParent()) {
            // If no root element was specified, then just wrap the XML in 'Request'
            $root = $parent->getData('xmlRoot') ?: $defaultRoot;
            if (empty($root['namespaces'])) {
                // Create the wrapping element with no namespaces
                $xml = new \SimpleXMLElement("<{$root['name']}/>");
            } else {
                // Create the wrapping element with an array of one or more namespaces
                $xml = "<{$root['name']} ";
                foreach ((array) $root['namespaces'] as $prefix => $uri) {
                    $xml .= is_numeric($prefix) ? "xmlns=\"{$uri}\" " : "xmlns:{$prefix}=\"{$uri}\" ";
                }
                $xml = new \SimpleXMLElement($xml . "/>");
            }
        } else {
            throw new RuntimeException('Parameter does not have a parent');
        }

        $node = $xml;
        if (!$param->getData('xmlFlattened') && ($param->getType() == 'object' || $param->getType() == 'array')) {
            $node = $xml->addChild($param->getWireName());
        }

        $this->addXml($node, $param, $value);
        $this->data[$command] = $xml;
    }

    /**
     * {@inheritdoc}
     */
    public function after(CommandInterface $command, RequestInterface $request)
    {
        if (isset($this->data[$command])) {
            $xml = $this->data[$command];
            unset($this->data[$command]);
            $request->setBody($xml->asXML());
            if ($this->contentType) {
                $request->setHeader('Content-Type', $this->contentType);
            }
        }
    }

    /**
     * Recursively build the XML body
     *
     * @param \SimpleXMLElement $xml   XML to modify
     * @param Parameter         $param API Parameter
     * @param mixed             $value Value to add
     */
    protected function addXml(\SimpleXMLElement $xml, Parameter $param, $value)
    {
        // Determine the name of the element
        $node = $param->getWireName();
        // Check if this property has a particular namespace
        $namespace = $param->getData('xmlNamespace');

        if ($param->getType() == 'array') {
            if ($items = $param->getItems()) {
                $name = $items->getWireName();
                foreach ($value as $v) {
                    if ($items->getType() == 'object' || $items->getType() == 'array') {
                        $child = $xml->addChild($name, null, $namespace);
                        $this->addXml($child, $items, $v);
                    } else {
                        $xml->addChild($name, $v, $namespace);
                    }
                }
            }
        } elseif ($param->getType() == 'object') {
            foreach ($value as $name => $v) {
                if ($property = $param->getProperty($name)) {
                    if ($property->getType() == 'object' || $property->getType() == 'array') {
                        // Account for flat arrays, meaning the contents of the array are not wrapped in a container
                        $child = $property->getData('xmlFlattened') ? $xml : $xml->addChild($property->getWireName());
                        $this->addXml($child, $property, $v);
                    } else {
                        if ($property->getData('xmlAttribute')) {
                            $xml->addAttribute($property->getWireName(), $v, $property->getData('xmlNamespace'));
                        } else {
                            $xml->addChild($property->getWireName(), $v, $property->getData('xmlNamespace'));
                        }
                    }
                }
            }
        } elseif ($param->getData('xmlAttribute')) {
            $xml->addAttribute($node, $value, $namespace);
        } else {
            $xml->addChild($node, $value, $namespace);
        }
    }
}
