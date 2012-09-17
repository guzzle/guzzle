<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Request;

use Guzzle\Common\Collection;
use Guzzle\Service\Command\LocationVisitor\Request\PostFieldVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Request\PostFieldVisitor
 */
class PostFieldVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = $this->getNestedCommand('postField')->getParam('foo');
        $visitor->visit($this->command, $this->request, $param->setRename('test'), '123');
        $this->assertEquals('123', (string) $this->request->getPostField('test'));
    }

    public function testRecursivelyBuildsPostFields()
    {
        $command = $this->getNestedCommand('postField');
        $data = new Collection();
        $command->validate($data);
        $visitor = new Visitor();
        $param = $this->getNestedCommand('postField')->getParam('foo');
        $visitor->visit($this->command, $this->request, $param->setRename('Foo'), $data['foo']);
        $visitor->after($this->command, $this->request);
        $this->assertEquals(
            'Foo[test][baz]=1&Foo[test][Jenga_Yall!]=HELLO&Foo[bar]=123',
            rawurldecode((string) $this->request->getPostFields())
        );
    }
}
