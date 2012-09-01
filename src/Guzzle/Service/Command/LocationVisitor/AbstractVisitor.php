<?php

namespace Guzzle\Service\Command\LocationVisitor;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;

/**
 * Default shared behavior for location visitors
 */
abstract class AbstractVisitor implements LocationVisitorInterface
{
    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function after(CommandInterface $command, RequestInterface $request) {}

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function visit(CommandInterface $command, RequestInterface $request, $key, $value) {}
}
