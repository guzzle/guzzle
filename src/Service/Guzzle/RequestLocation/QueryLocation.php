<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Service\Guzzle\Description\Operation;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;

/**
 * Adds query string values to requests
 */
class QueryLocation extends AbstractLocation
{
    public function visit(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Parameter $param,
        array $context
    ) {
        $request->setHeader(
            $param->getWireName(),
            $this->prepareValue($command[$param->getName()], $param)
        );
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
                    $request->getQuery()[$key] = $this->prepareValue(
                        $value,
                        $additional
                    );
                }
            }
        }
    }
}
