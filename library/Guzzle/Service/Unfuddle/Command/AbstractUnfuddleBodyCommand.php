<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command;

use Guzzle\Service\Command\AbstractCommand;
use Guzzle\Http\EntityBody;

/**
 * Base unfuddle command for sending commands with XML bodies
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractUnfuddleBodyCommand extends AbstractUnfuddleCommand
{
    /**
     * @var \SimpleXMLElement
     */
    protected $xml;
    
    /**
     * @var string The element name that contains all of the settings of the body
     */
    protected $containingElement = 'container';

    /**
     * Get the XML body that will be sent
     *
     * @return \SimpleXMLElement
     */
    public function getXmlBody()
    {
        if (!$this->xml) {
            $this->xml = new \SimpleXMLElement('<' . $this->containingElement . ' />');
        }
        
        return $this->xml;
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();

        if ($this->xml) {
            $this->request->setBody(EntityBody::factory($this->getXmlBody()->asXML()));
            $this->request->setHeader('Content-Type', 'application/xml');
        }
    }

    /**
     * Set an element value on the top-level document
     *
     * @param string $element
     * @param mixed $value
     * 
     * @return AbstractUnfuddleCommand
     */
    protected function setXmlValue($element, $value)
    {
        $xml = $this->getXmlBody();
        if ($xml->{$element}) {
            $xml->{$element} = $value;
        } else {
            $xml->addChild($element, $value);
        }
        
        return $this;
    }
}