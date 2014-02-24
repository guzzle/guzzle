<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Post\PostBodyInterface;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;
use GuzzleHttp\Service\Guzzle\Description\Operation;

/**
 * Adds POST fields to a request
 */
class PostFieldLocation extends AbstractLocation
{
    public function visit(
        RequestInterface $request,
        Parameter $param,
        $value,
        array $context
    ) {
        $body = $request->getBody();
        if (!($body instanceof PostBodyInterface)) {
            throw new \RuntimeException('Must be a POST body interface');
        }

        $body->setField(
            $param->getWireName(),
            $this->prepareValue($value, $param)
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

            $body = $request->getBody();
            if (!($body instanceof PostBodyInterface)) {
                throw new \RuntimeException('Must be a POST body interface');
            }

            foreach ($command->toArray() as $key => $value) {
                if (!$operation->hasParam($key)) {
                    $body->setField(
                        $key,
                        $this->prepareValue($value, $additional)
                    );
                }
            }
        }
    }
}
