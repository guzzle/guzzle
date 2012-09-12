<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor;

use Guzzle\Common\Collection;
use Guzzle\Service\Command\LocationVisitor\QueryVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\QueryVisitor
 */
class QueryVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $visitor->visit($this->command, $this->request, 'test', '123');
        $this->assertEquals('123', $this->request->getQuery()->get('test'));
    }

    /**
     * @covers Guzzle\Service\Command\LocationVisitor\QueryVisitor
     * @covers Guzzle\Service\Command\LocationVisitor\AbstractVisitor::resolveRecursively
     */
    public function testRecursivelyBuildsQueryStrings()
    {
        $command = $this->getNestedCommand('query');
        $data = new Collection();
        $command->validate($data);
        $visitor = new Visitor();
        $visitor->visit($this->command, $this->request, 'Foo', $data['foo'], $command->getParam('foo'));
        $visitor->after($this->command, $this->request);
        $this->assertEquals(
            '?Foo[test][baz]=1&Foo[test][Jenga_Yall!]=HELLO&Foo[bar]=123',
            rawurldecode((string) $this->request->getQuery())
        );
    }
}
