<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor;

use Guzzle\Service\Command\LocationVisitor\HeaderVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\HeaderVisitor
 */
class HeaderVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $visitor->visit($this->command, $this->request, 'test', '123');
        $this->assertEquals('123', (string) $this->request->getHeader('test'));
    }
}
