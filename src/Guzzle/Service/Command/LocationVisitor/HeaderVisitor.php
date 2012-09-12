<?php

namespace Guzzle\Service\Command\LocationVisitor;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Description\ApiParam;
use Guzzle\Service\Command\CommandInterface;

/**
 * Visitor used to apply a parameter to a header value
 */
class HeaderVisitor extends AbstractVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, RequestInterface $request, $key, $value, ApiParam $param = null)
    {
        $request->setHeader($key, $value);
    }
}
