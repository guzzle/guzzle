<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\CommandInterface;

/**
 * Location visitor used to add a particular header of a response to a key in the result
 */
class HeaderVisitor extends AbstractResponseVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, Response $response, Parameter $param, &$value)
    {
        if ($param->getType() == 'object' && $param->getAdditionalProperties() instanceof Parameter) {
            if ($prefix = $param->getSentAs()) {
                $len = strlen($prefix);
                foreach ($response->getHeaders() as $key => $header) {
                    if (stripos($key, $prefix) === 0) {
                        // Account for multi-value headers
                        $value[substr($key, $len)] = count($header) == 1 ? end($header) : $header;
                    }
                }
            }
        } else {
            $value[$param->getName()] = (string) $response->getHeader($param->getWireName());
        }
    }
}
