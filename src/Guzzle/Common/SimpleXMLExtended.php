<?php

namespace Guzzle\Common;

/**
 * @author Markus Bachmann <markus.bachmann@digital-connect.de>
 */
class SimpleXMLExtended extends \SimpleXMLElement
{
    public function addCDATASection($value)
    {
        $node = dom_import_simplexml($this);
        $owner = $node->ownerDocument;
        $node->appendChild($owner->createCDATASection($value));
    }
}