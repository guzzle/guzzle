<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Post\PostBodyInterface;
use GuzzleHttp\Post\PostFileInterface;
use GuzzleHttp\Post\PostFile;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;

/**
 * Adds POST files to a request
 */
class PostFileLocation extends AbstractLocation
{
    public function visit(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Parameter $param,
        array $context
    ) {
        $body = $request->getBody();
        if (!($body instanceof PostBodyInterface)) {
            throw new \RuntimeException('Must be a POST body interface');
        }

        $value = $param->filter($command[$param->getName()]);
        if (!($value instanceof PostFileInterface)) {
            $value = new PostFile($param->getWireName(), $value);
        }

        $body->addFile($value);
    }
}
