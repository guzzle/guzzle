<?php

namespace GuzzleHttp\Service\Guzzle\ResponseLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;

/**
 * Extracts the status code of a response into a result field
 */
class StatusCodeLocation extends AbstractLocation
{
    public function visit(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $param,
        &$result,
        array $context = []
    ) {
        $result[$param->getName()] = $param->filter($response->getStatusCode());
    }
}
