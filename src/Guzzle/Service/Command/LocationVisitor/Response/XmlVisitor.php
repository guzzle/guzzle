<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\CommandInterface;

/**
 * Location visitor used to marshal XML response data into a formatted array
 */
class XmlVisitor extends AbstractResponseVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, Response $response, Parameter $param, &$value)
    {

    }
}
