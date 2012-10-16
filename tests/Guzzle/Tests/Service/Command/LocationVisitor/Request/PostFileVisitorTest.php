<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Request;

use Guzzle\Http\Message\PostFile;
use Guzzle\Service\Command\LocationVisitor\Request\PostFileVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Request\PostFileVisitor
 */
class PostFileVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = $this->getNestedCommand('postFile')->getParam('foo');

        // Test using a path to a file
        $visitor->visit($this->command, $this->request, $param->setSentAs('test_3'), __FILE__);
        $this->assertInternalType('array', $this->request->getPostFile('test_3'));

        // Test with a PostFile
        $visitor->visit($this->command, $this->request, $param->setSentAs(null), new PostFile('baz', __FILE__));
        $this->assertInternalType('array', $this->request->getPostFile('baz'));
    }
}
