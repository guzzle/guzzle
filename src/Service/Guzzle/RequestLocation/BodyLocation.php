<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;
use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\Stream;

/**
 * Adds a body to a request
 */
class BodyLocation extends AbstractLocation
{
    public function visit(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Parameter $param,
        array $context
    ) {
        $value = $command[$param->getName()];
        $request->setBody(Stream::factory($param->filter($value)));
    }
}
