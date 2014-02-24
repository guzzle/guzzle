<?php

namespace GuzzleHttp\Service\Guzzle\ResponseLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;

/**
 * Extracts elements from an XML document
 */
class XmlLocation extends AbstractLocation
{
    public function visit(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $param,
        &$result,
        array $context = []
    ) {

    }
}
