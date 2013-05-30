<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Service\Command\ArrayCommandInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;

/**
 * {@inheritdoc}
 * @codeCoverageIgnore
 */
abstract class AbstractResponseVisitor implements ResponseVisitorInterface
{
    public function before(ArrayCommandInterface $command, array &$result) {}

    public function after(ArrayCommandInterface $command) {}

    public function visit(
        ArrayCommandInterface $command,
        Response $response,
        Parameter $param,
        &$value,
        $context =  null
    ) {}
}
