<?php

namespace Guzzle\Service\Builder;

use Guzzle\Common\FromConfigInterface;
use Guzzle\Service\Exception\ServiceNotFoundException;

/**
 * Service builder to generate service builders and service clients from configuration settings
 */
interface ServiceBuilderInterface
{
    /**
     * Get a service using a registered builder
     *
     * @param string     $name      Name of the registered client to retrieve
     * @param bool|array $throwAway Set to TRUE to not store the client for later retrieval from the ServiceBuilder.
     *                              If an array is specified, that data will overwrite the configured params
     *
     * @return FromConfigInterface
     * @throws ServiceNotFoundException when a client cannot be found by name
     */
    public function get($name, $throwAway = false);

    /**
     * Register a service by name with the service builder
     *
     * @param string $key     Name of the client to register
     * @param mixed  $service Service to register
     *
     * @return ServiceBuilderInterface
     */
    public function set($key, $service);
}
