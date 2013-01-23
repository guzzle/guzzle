<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Service\Command\CommandInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;

/**
 * {@inheritdoc}
 */
abstract class AbstractResponseVisitor implements ResponseVisitorInterface
{
    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function before(CommandInterface $command, array &$result) {}

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function after(CommandInterface $command) {}

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function visit(CommandInterface $command, Response $response, Parameter $param, &$value, $context =  null) {}
}
