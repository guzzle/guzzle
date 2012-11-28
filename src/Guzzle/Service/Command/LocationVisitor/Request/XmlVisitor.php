<?php

namespace Guzzle\Service\Command\LocationVisitor\Request;

use Guzzle\Common\Exception\RuntimeException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Description\Operation;
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
     *
     * @throws RuntimeException
     */
    public function visit(CommandInterface $command, RequestInterface $request, Parameter $param, $value)
    {
        if (isset($this->data[$command])) {
            $xml = $this->data[$command];
        } elseif ($parent = $param->getParent()) {
            $xml = $this->createRootElement($parent);
        } else {
            throw new RuntimeException('Parameter does not have a parent');
        }

        $this->addXml($xml, $param, $value);

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
     * Create the root XML element to use with a request
     *
     * @param Operation $operation Operation object
     *
     * @return \SimpleXMLElement
     */
    protected function createRootElement(Operation $operation)
    {
        static $defaultRoot = array('name' => 'Request');
        // If no root element was specified, then just wrap the XML in 'Request'
        $root = $operation->getData('xmlRoot') ? : $defaultRoot;

        // Create the wrapping element with no namespaces if no namespaces were present
        if (empty($root['namespaces'])) {
            return new \SimpleXMLElement("<{$root['name']}/>");
        }

        // Create the wrapping element with an array of one or more namespaces
        $xml = "<{$root['name']} ";
        foreach ((array) $root['namespaces'] as $prefix => $uri) {
            $xml .= is_numeric($prefix) ? "xmlns=\"{$uri}\" " : "xmlns:{$prefix}=\"{$uri}\" ";
        }

        return new \SimpleXMLElement($xml . "/>");
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
        $nodeName = $param->getWireName();
        // Determine the particular namespace (if this property has one)
        $namespace = $param->getData('xmlNamespace');
        // Filter the value
        $value = $param->filter($value);
        // Account for flat arrays, meaning the contents of the array are not wrapped in a container

        if ($param->getType() == 'object' || $param->getType() == 'array') {
            // Object or array data may be placed inside some particular node
            $childNode = $param->getData('xmlFlattened')
                ? $xml
                : $xml->addChild($nodeName, null, $namespace);

            if ($param->getType() == 'object') {
                $this->addXmlObject($childNode, $param, $value);
            } else {
                $this->addXmlArray($childNode, $param, $value);
            }
        } elseif ($param->getData('xmlAttribute')) {
            $xml->addAttribute($nodeName, $value, $namespace);
        } else {
            $xml->addChild($nodeName, $value, $namespace);
        }
    }

    /**
     * Add an array to the XML
     *
     * @param \SimpleXMLElement $xml       XML to modify
     * @param Parameter         $param     API Parameter
     * @param mixed             $value     Value to add
     */
    protected function addXmlArray(\SimpleXMLElement $xml, Parameter $param, $value)
    {
        if ($itemsParam = $param->getItems()) {
            foreach ($value as $v) {
                // Don't add null values
                if ($v !== null) {
                    $this->addXml($xml, $itemsParam, $v);
                }
            }
        }
    }

    /**
     * Add an object to the XML
     *
     * @param \SimpleXMLElement $xml   XML to modify
     * @param Parameter         $param API Parameter
     * @param mixed             $value Value to add
     */
    protected function addXmlObject(\SimpleXMLElement $xml, Parameter $param, $value)
    {
        foreach ($value as $name => $v) {
            // Don't add null values
            if ($v !== null) {
                // Continue recursing if a matching property is found
                if ($propertyParam = $param->getProperty($name)) {
                    $this->addXml($xml, $propertyParam, $v);
                }
            }
        }
    }
}
