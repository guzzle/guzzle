<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Response;

use Guzzle\Service\Description\Parameter;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\Response\HeaderVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Response\HeaderVisitor
 */
class HeaderVisitorTest extends AbstractResponseVisitorTest
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'location' => 'header',
            'name'     => 'ContentType',
            'sentAs'   => 'Content-Type'
        ));
        $visitor->visit($this->command, $this->response, $param, $this->value);
        $this->assertEquals('text/plain', $this->value['ContentType']);
    }
}
