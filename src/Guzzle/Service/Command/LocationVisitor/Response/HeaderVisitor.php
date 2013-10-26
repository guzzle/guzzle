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
    public function visit(
        CommandInterface $command,
        Response $response,
        Parameter $param,
        &$value,
        $context = null
    )
    {
        $name = $param->getName();
        $sentAs = $param->getWireName();
        $header = $response->getHeader($sentAs);
        if (!empty($header)) {
            $value[$name] = $param->filter((string)$header);
        }

        // Handle additional, undefined headers
        $additional = $param->getAdditionalProperties();

        if ($additional instanceof Parameter) {
            if ($prefix = $param->getSentAs()) {
                // Process prefixed headers
                $this->processPrefixedHeaders($prefix, $response, $param, $value);

            } else {
                // Process all headers with the additionalProperties schema
                $this->processAllHeaders($response, $additional, $value);
            }

        } elseif ($additional === null || $additional === true) {
            // Process all headers with main schema
            $this->processAllHeaders($response, $param, $value);
        }
    }

    /**
     * Process a prefixed header array
     *
     * @param string    $prefix   Header prefix to use
     * @param Response  $response Response that contains the headers
     * @param Parameter $param    Parameter object
     * @param array     $value    Value response array to modify
     */
    protected function processPrefixedHeaders($prefix, Response $response, Parameter $param, &$value)
    {
        // Grab prefixed headers that should be placed into an array with the prefix stripped
        $container = $param->getName();
        $len = strlen($prefix);
        $headers = $response->getHeaders()->toArray();

        // Find all matching headers and place them into the containing element
        foreach ($headers as $key => $header) {
            if (stripos($key, $prefix) === 0) {
                // Account for multi-value headers
                $value[$container][substr($key, $len)] = count($header) == 1 ? end($header) : $header;
            }
        }
    }

    /**
     * Process a prefixed header array
     *
     * @param Response  $response Response that contains the headers
     * @param Parameter $param    Parameter object
     * @param array     $value    Value response array to modify
     */
    protected function processAllHeaders(Response $response, Parameter $param, &$value)
    {
        $headers = $response->getHeaders()->toArray();
        foreach ($headers as $key => $header) {
            $header = count($header) == 1 ? end($header) : $header;
            $header = $param->filter($header);
            $value[$key] = $header;
        }
    }
}
