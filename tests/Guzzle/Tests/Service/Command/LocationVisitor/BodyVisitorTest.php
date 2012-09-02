<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor;

use Guzzle\Service\Command\LocationVisitor\BodyVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\BodyVisitor
 */
class BodyVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $visitor->visit($this->command, $this->request, null, '123');
        $this->assertEquals('123', (string) $this->request->getBody());
    }
}
