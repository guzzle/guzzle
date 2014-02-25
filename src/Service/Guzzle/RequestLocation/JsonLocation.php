<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Operation;
use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\Stream;

/**
 * Creates a JSON document
 */
class JsonLocation extends AbstractLocation
{
    /** @var bool Whether or not to add a Content-Type header when JSON is found */
    private $jsonContentType;

    /** @var array */
    private $jsonData;

    /**
     * @param string $contentType Content-Type header to add to the request if
     *     JSON is added to the body. Pass an empty string to omit.
     */
    public function __construct($contentType = 'application/json')
    {
        $this->jsonContentType = $contentType;
    }

    public function visit(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Parameter $param,
        array $context
    ) {
        $this->jsonData[$param->getWireName()] = $this->prepareValue(
            $command[$param->getName()],
            $param
        );
    }

    public function after(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Operation $operation,
        array $context
    ) {
        $data = $this->jsonData;
        $this->jsonData = null;

        // Add additional parameters to the JSON document
        $additional = $operation->getAdditionalParameters();
        if ($additional && $additional->getLocation() == $this->locationName) {
            foreach ($command->toArray() as $key => $value) {
                if (!$operation->hasParam($key)) {
                    $data[$key] = $this->prepareValue($value, $additional);
                }
            }
        }

        // Don't overwrite the Content-Type if one is set
        if ($this->jsonContentType && !$request->hasHeader('Content-Type')) {
            $request->setHeader('Content-Type', $this->jsonContentType);
        }

        $request->setBody(Stream::factory(json_encode($data)));
    }
}
