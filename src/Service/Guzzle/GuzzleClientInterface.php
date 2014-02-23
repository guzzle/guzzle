<?php

namespace GuzzleHttp\Service\Guzzle;

use GuzzleHttp\Service\ServiceClientInterface;
use GuzzleHttp\Service\Guzzle\Description\GuzzleDescription;

/**
 * Guzzle web service client
 */
interface GuzzleClientInterface extends ServiceClientInterface
{
    /**
     * Returns the service description used by the client
     *
     * @return GuzzleDescription
     */
    public function getDescription();
}
