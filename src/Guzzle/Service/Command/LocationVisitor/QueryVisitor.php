<?php

namespace Guzzle\Service\Command\LocationVisitor;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Description\ApiParam;
use Guzzle\Service\Command\CommandInterface;

/**
 * Visitor used to apply a parameter to a request's query string
 */
class QueryVisitor extends AbstractVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, RequestInterface $request, $key, $value, ApiParam $param = null)
    {
        $request->getQuery()->set($key, is_array($value) ? $this->resolveRecursively($value, $param) : $value);
    }
}
