<?php
namespace Guzzle\Tests\Service\Mock\Response;

use SimpleXMLElement;
use Guzzle\Service\Command\LocationVisitor\Response\XmlVisitor as BaseVisitor;

class XmlVisitor extends BaseVisitor
{
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
}
