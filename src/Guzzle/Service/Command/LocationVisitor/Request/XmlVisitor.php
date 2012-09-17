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
        if (isset($this->data[$command])) {
            $xml = $this->data[$command];
        } elseif ($parent = $param->getParent()) {
            // If no root element was specified, then just wrap the XML in 'Request'
            $root = $parent->getData('root') ?: 'Request';
            // Create the wrapping element
            if ($ns = $parent->getData('ns')) {
                $xml = new \SimpleXMLElement("<{$root} xmlns=\"{$ns}\"/>");
            } else {
                $xml = new \SimpleXMLElement("<{$root}/>");
            }
        } else {
            throw new RuntimeException('Parameter does not have a parent');
        }

        $node = $xml;
        if ($param->getType() == 'object' || $param->getType() == 'array') {
            $node = $xml->addChild($param->getRename() ?: $param->getName());
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
            $request->setBody($xml->asXML())->removeHeader('Expect');
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
        $node = $param->getRename() ?: $param->getName();
        // Check if this property has a particular namespace
        $namespace = $param->getData('namespace') ?: null;

        if ($param->getType() == 'array') {
            if ($items = $param->getItems()) {
                $name = $items->getRename();
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
                        $child = $xml->addChild($name);
                        $this->addXml($child, $property, $v);
                    } else {
                        if ($property->getData('attribute')) {
                            $xml->addAttribute($property->getRename() ?: $property->getName(), $v, $namespace);
                        } else {
                            $xml->addChild($name, $v, $namespace);
                        }
                    }
                }
            }
        } elseif ($param->getData('attribute')) {
            $xml->addAttribute($node, $value, $namespace);
        } else {
            $xml->addChild($node, $value, $namespace);
        }
    }
}
