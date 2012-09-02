<?php

namespace Guzzle\Service\Command\LocationVisitor;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;

/**
 * Visitor used to apply a parameter to a header value
 */
class HeaderVisitor extends AbstractVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, RequestInterface $request, $key, $value)
    {
        $request->setHeader($key, $value);
    }
}
