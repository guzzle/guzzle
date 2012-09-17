<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Request;

use Guzzle\Service\Command\LocationVisitor\Request\BodyVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Request\BodyVisitor
 */
class BodyVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = $this->getNestedCommand('body')->getParam('foo')->setRename('Foo');
        $visitor->visit($this->command, $this->request, $param, '123');
        $this->assertEquals('123', (string) $this->request->getBody());
    }
}
