<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Model;

use Guzzle\Common\XmlElement;

/**
 * Feed class
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class Feed
{
    /**
     * @var Guzzle\Common\XmlElement
     */
    protected $xml;

    /**
     * Initialize feed, add base nodes
     */
    public function __construct()
    {
        $this->xml = new XmlElement('<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd" />');
        
        $header = $this->xml->addChild('Header');
        $header->addChild('DocumentVersion', '1.01');
        $header->addChild('MerchantIdentifiter', 'AYR3L7CAZLM5B');

        $this->xml->addChild('MessageType');
        $this->xml->addChild('PurgeAndReplace');
        $this->xml->addChild('Message');
    }

    /**
     * Get message node
     *
     * @return Guzzle\Common\XmlElement
     */
    public function getMessage()
    {
        return $this->getXml()->Message;
    }

    /**
     * Set message node
     * 
     * @param mixed $message string or SimpleXMLElement message block
     *
     * @return AbstractFeed
     */
    public function setMessage($message)
    {
        if (!($message instanceof XmlElement)) {
            $message = new XmlElement($message);
        }
        unset($this->xml->Message);
        $this->xml->addChild($message);

        return $this;
    }

    /**
     * Get XML object
     *
     * @return Guzzle\Common\XmlElement
     */
    public function getXml()
    {
        return $this->xml;
    }

    /**
     * Get XML string
     *
     * @return string
     */
    public function toXml()
    {
        $xml = $this->xml->asXML();

        // Indent output using DOMDocument
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->loadXML($xml);

        return $doc->saveXML();
    }

    /**
     * Alias of toXml()
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toXml();
    }
}