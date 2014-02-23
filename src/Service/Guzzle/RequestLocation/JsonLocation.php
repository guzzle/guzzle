<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\Stream;

/**
 * Creates a JSON document
 */
class JsonLocation extends AbstractLocation
{
    /** @var bool Whether or not to add a Content-Type header when JSON is found */
    protected $jsonContentType;

    /** @var \SplObjectStorage Data object for persisting JSON data */
    protected $data;

    /**
     * @param string $contentType Content-Type header to add to the request if
     *     JSON is added to the body. Pass an empty string to omit.
     */
    public function __construct($contentType = 'application/json')
    {
        $this->jsonContentType = $contentType;
        $this->data = new \SplObjectStorage();
    }

    public function visit(
        RequestInterface $request,
        Parameter $param,
        $value,
        array $context
    ) {
        $json = isset($this->data[$context['command']])
            ? $this->data[$context['command']]
            : [];
        $json[$param->getWireName()] = $this->prepareValue($value, $param);
        $this->data[$context['command']] = $json;
    }

    public function after(
        RequestInterface $request,
        array $context
    ) {
        if (!isset($this->data[$context['command']])) {
            return;
        }

        // Don't overwrite the Content-Type if one is set
        if ($this->jsonContentType && !$request->hasHeader('Content-Type')) {
            $request->setHeader('Content-Type', $this->jsonContentType);
        }

        $request->setBody(Stream::factory(json_encode($this->data[$context['command']])));
        unset($this->data[$context['command']]);
    }
}
