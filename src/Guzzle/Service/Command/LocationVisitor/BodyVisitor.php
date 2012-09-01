<?php

namespace Guzzle\Service\Command\LocationVisitor;

use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;

/**
 * Visitor used to apply a body to a request
 */
class BodyVisitor extends AbstractVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, RequestInterface $request, $key, $value)
    {
        $request->setBody(EntityBody::factory($value));
    }
}
