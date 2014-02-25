<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Operation;
use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\Stream;

/**
 * Creates an XML document
 */
class XmlLocation extends AbstractLocation
{
    /** @var \XMLWriter XML writer resource */
    protected $writer;

    /** @var bool Content-Type header added when XML is found */
    protected $contentType;

    /**
     * @param string $contentType Set to a non-empty string to add a
     *     Content-Type header to a request if any XML content is added to the
     *     body. Pass an empty string to disable the addition of the header.
     */
    public function __construct($contentType = 'application/xml')
    {
        $this->contentType = $contentType;
        $this->data = new \SplObjectStorage();
    }

    public function visit(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Parameter $param,
        array $context
    ) {
        $this->visitWithValue($command[$param->getName()], $param, $command);
    }

    public function after(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Operation $operation,
        array $context
    ) {
        $additional = $operation->getAdditionalParameters();
        if ($additional && $additional->getLocation() == $this->locationName) {
            foreach ($command->toArray() as $key => $value) {
                if (!$operation->hasParam($key)) {
                    $this->visitWithValue($value, $additional, $command);
                }
            }
        }

        // If data was found that needs to be serialized, then do so
        $xml = null;
        if ($this->writer) {
            $xml = $this->finishDocument($this->writer);
        } elseif ($operation->getData('xmlAllowEmpty')) {
            // Check if XML should always be sent for the command
            $writer = $this->createRootElement($operation);
            $xml = $this->finishDocument($writer);
        }

        if ($xml) {
            $request->setBody(Stream::factory($xml));
            // Don't overwrite the Content-Type if one is set
            if ($this->contentType && !$request->hasHeader('Content-Type')) {
                $request->setHeader('Content-Type', $this->contentType);
            }
        }

        $this->writer = null;
    }

    /**
     * Create the root XML element to use with a request
     *
     * @param Operation $operation Operation object
     *
     * @return \XMLWriter
     */
    protected function createRootElement(Operation $operation)
    {
        static $defaultRoot = array('name' => 'Request');
        // If no root element was specified, then just wrap the XML in 'Request'
        $root = $operation->getData('xmlRoot') ?: $defaultRoot;
        // Allow the XML declaration to be customized with xmlEncoding
        $encoding = $operation->getData('xmlEncoding');
        $writer = $this->startDocument($encoding);
        $writer->startElement($root['name']);

        // Create the wrapping element with no namespaces if no namespaces were present
        if (!empty($root['namespaces'])) {
            // Create the wrapping element with an array of one or more namespaces
            foreach ((array) $root['namespaces'] as $prefix => $uri) {
                $nsLabel = 'xmlns';
                if (!is_numeric($prefix)) {
                    $nsLabel .= ':'.$prefix;
                }
                $writer->writeAttribute($nsLabel, $uri);
            }
        }

        return $writer;
    }

    /**
     * Recursively build the XML body
     *
     * @param \XMLWriter $writer XML to modify
     * @param Parameter  $param     API Parameter
     * @param mixed      $value     Value to add
     */
    protected function addXml(\XMLWriter $writer, Parameter $param, $value)
    {
        if ($value === null) {
            return;
        }

        $value = $param->filter($value);
        $type = $param->getType();
        $name = $param->getWireName();
        $prefix = null;
        $namespace = $param->getData('xmlNamespace');
        if (false !== strpos($name, ':')) {
            list($prefix, $name) = explode(':', $name, 2);
        }

        if ($type == 'object' || $type == 'array') {
            if (!$param->getData('xmlFlattened')) {
                $writer->startElementNS(null, $name, $namespace);
            }
            if ($param->getType() == 'array') {
                $this->addXmlArray($writer, $param, $value);
            } elseif ($param->getType() == 'object') {
                $this->addXmlObject($writer, $param, $value);
            }
            if (!$param->getData('xmlFlattened')) {
                $writer->endElement();
            }
            return;
        }
        if ($param->getData('xmlAttribute')) {
            $this->writeAttribute($writer, $prefix, $name, $namespace, $value);
        } else {
            $this->writeElement($writer, $prefix, $name, $namespace, $value);
        }
    }

    /**
     * Write an attribute with namespace if used
     *
     * @param  \XMLWriter $writer XMLWriter instance
     * @param  string     $prefix    Namespace prefix if any
     * @param  string     $name      Attribute name
     * @param  string     $namespace The uri of the namespace
     * @param  string     $value     The attribute content
     */
    protected function writeAttribute($writer, $prefix, $name, $namespace, $value)
    {
        if (empty($namespace)) {
            $writer->writeAttribute($name, $value);
        } else {
            $writer->writeAttributeNS($prefix, $name, $namespace, $value);
        }
    }

    /**
     * Write an element with namespace if used
     *
     * @param  \XMLWriter $writer XML writer resource
     * @param  string     $prefix    Namespace prefix if any
     * @param  string     $name      Element name
     * @param  string     $namespace The uri of the namespace
     * @param  string     $value     The element content
     */
    protected function writeElement(\XMLWriter $writer, $prefix, $name, $namespace, $value)
    {
        $writer->startElementNS($prefix, $name, $namespace);
        if (strpbrk($value, '<>&')) {
            $writer->writeCData($value);
        } else {
            $writer->writeRaw($value);
        }
        $writer->endElement();
    }

    /**
     * Create a new xml writer and start a document
     *
     * @param  string $encoding document encoding
     *
     * @return \XMLWriter the writer resource
     */
    protected function startDocument($encoding)
    {
        $this->writer = new \XMLWriter();
        $this->writer->openMemory();
        $this->writer->startDocument('1.0', $encoding);

        return $this->writer;
    }

    /**
     * End the document and return the output
     *
     * @param \XMLWriter $writer
     *
     * @return \string the writer resource
     */
    protected function finishDocument($writer)
    {
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Add an array to the XML
     */
    protected function addXmlArray(\XMLWriter $writer, Parameter $param, &$value)
    {
        if ($items = $param->getItems()) {
            foreach ($value as $v) {
                $this->addXml($writer, $items, $v);
            }
        }
    }

    /**
     * Add an object to the XML
     */
    protected function addXmlObject(\XMLWriter $writer, Parameter $param, &$value)
    {
        foreach ($value as $name => $v) {
            if ($property = $param->getProperty($name)) {
                $this->addXml($writer, $property, $v);
            }
        }
    }

    private function visitWithValue(
        $value,
        Parameter $param,
        GuzzleCommandInterface $command
    ) {
        if (!$this->writer) {
            $this->createRootElement($command->getOperation());
        }

        $this->addXml($this->writer, $param, $value);
    }
}
