<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Post\PostBodyInterface;
use GuzzleHttp\Post\PostFileInterface;
use GuzzleHttp\Post\PostFile;

/**
 * Adds POST files to a request
 */
class PostFileLocation extends AbstractLocation
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

        $value = $param->filter($value);
        if (!($value instanceof PostFileInterface)) {
            $value = new PostFile($param->getWireName(), $value);
        }

        $body->addFile($value);
    }
}
