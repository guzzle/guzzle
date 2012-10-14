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
            // Grab prefixed headers that should be placed into an array with the prefix stripped
            if ($prefix = $param->getSentAs()) {
                $container = $param->getName();
                $len = strlen($prefix);
                // Find all matching headers and place them into the containing element
                foreach ($response->getHeaders() as $key => $header) {
                    if (stripos($key, $prefix) === 0) {
                        // Account for multi-value headers
                        $value[$container][substr($key, $len)] = count($header) == 1 ? end($header) : $header;
                    }
                }
            }
        } else {
            $value[$param->getName()] = (string) $response->getHeader($param->getWireName());
        }
    }
}
