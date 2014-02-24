<?php

namespace GuzzleHttp\Service\Guzzle\ResponseLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;

abstract class AbstractLocation implements ResponseLocationInterface
{
    /** @var string */
    protected $locationName;

    /**
     * Set the name of the location
     *
     * @param $locationName
     */
    public function __construct($locationName)
    {
        $this->locationName = $locationName;
    }

    public function before(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $model,
        &$result,
        array $context = []
    ) {}

    public function after(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $model,
        &$result,
        array $context = []
    ) {}

    public function visit(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $param,
        &$result,
        array $context = []
    ) {}
}
