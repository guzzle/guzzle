<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;

/**
 * Handles locations specified in a service description
 */
interface RequestLocationInterface
{
    /**
     * Visits a location for each top-level parameter
     *
     * @param RequestInterface $request Request being modified
     * @param Parameter        $param   Parameter being visited
     * @param mixed            $value   Associated value
     * @param array            $context Associative array containing a client
     *                                  and command key.
     */
    public function visit(
        RequestInterface $request,
        Parameter $param,
        $value,
        array $context
    );

    /**
     * Called when all of the parameters of a command have been visited.
     *
     * @param RequestInterface $request Request being modified
     * @param array            $context Associative array containing a client
     *                                  and command key.
     */
    public function after(
        RequestInterface $request,
        array $context
    );
}
