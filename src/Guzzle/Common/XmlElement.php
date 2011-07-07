<?php

namespace Guzzle\Common;

/**
 * Extended functionality XML element class
 *
 * This class extends SimpleXMLElement, to allow adding simpleXMLElemnent child
 * objects to each other.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class XmlElement extends \SimpleXMLElement
{
    /**
     * Magic method, alias of toXML()
     *
     * @return string
     */
    public function __toString()
    {
        return $this->asXML();
    }

    /**
     * Extended addChild method - supports appending another Element
     *
     * @param string|Element $name
     *
     * @param string|SimpleXMLElement $value XML data to add
     * @param string $namespace Namespace of the element
     *
     * @return Element
     */
    public function addChild($name, $value = null, $namespace = null)
    {
        // If this is not a SimpleXMLElement, then add normally
        if (!($name instanceof \SimpleXMLElement)) {
            return parent::addChild($name, $value, $namespace);
        }

        $nodeName = $name->getName();

        // If the parent node has no children, then cast to a string and add
        if (!count($name->children())) {
            return parent::addChild($nodeName, (string) $name, $namespace);
        }

        $e = $this->addChild($nodeName);

        // Add parent attributes to the newly added child node
        foreach ($name->attributes() as $key => $value) {
            $e->addAttribute((string) $key, (string) $value);
        }

        // Add the children of the parent node
        foreach ($name->children() as $child) {
            $childEle = $e->addChild($child);
            $attributes = $childEle->attributes($namespace);
            foreach ($child->attributes() as $key => $val) {
                if (!isset($attributes->{$key})) {
                    $childEle->addAttribute((string) $key, (string) $val);
                }
            }
        }

        return $e;
    }

    /**
     * Output formatted XML string
     *
     * @return string
     */
    public function asFormattedXml()
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->loadXML($this->asXML());

        return $doc->saveXML();
    }
}