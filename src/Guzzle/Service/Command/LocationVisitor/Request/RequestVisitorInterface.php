<?php

namespace Guzzle\Service\Command\LocationVisitor\Request;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\ArrayCommandInterface;

/**
 * Location visitor used to add values to different locations in a request with different behaviors as needed
 */
interface RequestVisitorInterface
{
    /**
     * Called after visiting all parameters
     *
     * @param ArrayCommandInterface $command Command being visited
     * @param RequestInterface      $request Request being visited
     */
    public function after(ArrayCommandInterface $command, RequestInterface $request);

    /**
     * Called once for each parameter being visited that matches the location type
     *
     * @param ArrayCommandInterface $command Command being visited
     * @param RequestInterface      $request Request being visited
     * @param Parameter             $param   Parameter being visited
     * @param mixed                 $value   Value to set
     */
    public function visit(ArrayCommandInterface $command, RequestInterface $request, Parameter $param, $value);
}
