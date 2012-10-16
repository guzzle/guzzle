<?php

namespace Guzzle\Service\Command\LocationVisitor\Request;

use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Description\Parameter;

/**
 * Visitor used to apply a body to a request
 *
 * This visitor can use a data parameter of 'expect' to control the Expect header. Set the expect data parameter to
 * false to disable the expect header, or set the value to an integer so that the expect 100-continue header is only
 * added if the Content-Length of the entity body is greater than the value.
 */
class BodyVisitor extends AbstractRequestVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, RequestInterface $request, Parameter $param, $value)
    {
        $entityBody = EntityBody::factory($value);
        $request->setBody($entityBody);

        // Allow the `expect` data parameter to be set to remove the Expect header from the request
        $expectHeader = $param->getData('expect_header');
        if ($expectHeader === false) {
            $request->removeHeader('Expect');
        } elseif ($expectHeader !== true) {
            // Default to using a MB as the point in which to start using the expect header
            $expectHeader = $expectHeader ?: 1048576;
            // If the expect_header value is numeric then only add if the size is greater than the cutoff
            if (is_numeric($expectHeader) && $entityBody->getSize()) {
                if ($entityBody->getSize() < $expectHeader) {
                    $request->removeHeader('Expect');
                } else {
                    $request->setHeader('Expect', '100-Continue');
                }
            }
        }

        // Add the Content-Encoding header if one is set on the EntityBody
        if ($encoding = $entityBody->getContentEncoding()) {
            $request->setHeader('Content-Encoding', $encoding);
        }
    }
}
