<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Request;

use Guzzle\Service\Command\LocationVisitor\Request\HeaderVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Request\HeaderVisitor
 */
class HeaderVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = $this->getNestedCommand('header')->getParam('foo')->setSentAs('test');
        $visitor->visit($this->command, $this->request, $param, '123');
        $this->assertEquals('123', (string) $this->request->getHeader('test'));
    }
}
