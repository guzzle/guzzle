<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Request;

use Guzzle\Common\Collection;
use Guzzle\Service\Command\LocationVisitor\Request\QueryVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Request\QueryVisitor
 */
class QueryVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = $this->getNestedCommand('query')->getParam('foo')->setRename('test');
        $visitor->visit($this->command, $this->request, $param, '123');
        $this->assertEquals('123', $this->request->getQuery()->get('test'));
    }

    /**
     * @covers Guzzle\Service\Command\LocationVisitor\Request\QueryVisitor
     * @covers Guzzle\Service\Command\LocationVisitor\Request\AbstractRequestVisitor::resolveRecursively
     */
    public function testRecursivelyBuildsQueryStrings()
    {
        $command = $this->getNestedCommand('query');
        $data = new Collection();
        $command->validate($data);
        $visitor = new Visitor();
        $param = $this->getNestedCommand('query')->getParam('foo');
        $visitor->visit($this->command, $this->request, $param->setRename('Foo'), $data['foo']);
        $visitor->after($this->command, $this->request);
        $this->assertEquals(
            '?Foo[test][baz]=1&Foo[test][Jenga_Yall!]=HELLO&Foo[bar]=123',
            rawurldecode((string) $this->request->getQuery())
        );
    }
}
