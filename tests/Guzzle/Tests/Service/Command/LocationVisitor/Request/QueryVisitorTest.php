<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Request;

use Guzzle\Service\Command\LocationVisitor\Request\QueryVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Request\QueryVisitor
 */
class QueryVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = $this->getNestedCommand('query')->getParam('foo')->setSentAs('test');
        $visitor->visit($this->command, $this->request, $param, '123');
        $this->assertEquals('123', $this->request->getQuery()->get('test'));
    }

    /**
     * @covers Guzzle\Service\Command\LocationVisitor\Request\QueryVisitor
     * @covers Guzzle\Service\Command\LocationVisitor\Request\AbstractRequestVisitor::resolveRecursively
     */
    public function testRecursivelyBuildsQueryStrings()
    {
        $command = $this->getCommand('query');
        $command->getOperation()->getParam('foo')->setSentAs('Foo');
        $request = $command->prepare();
        $this->assertEquals(
            '?Foo[test][baz]=1&Foo[test][Jenga_Yall!]=HELLO&Foo[bar]=123',
            rawurldecode($request->getQuery())
        );
    }
}
