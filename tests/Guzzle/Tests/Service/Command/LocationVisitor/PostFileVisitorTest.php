<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor;

use Guzzle\Http\Message\PostFile;
use Guzzle\Service\Command\LocationVisitor\PostFileVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\PostFileVisitor
 */
class PostFileVisitorTest extends AbstractVisitorTestCase
{
    public function testVisitsLocation()
    {
        $visitor = new Visitor();

        // Test using a path to a file
        $visitor->visit($this->command, $this->request, 'test_3', __FILE__);
        $this->assertInternalType('array', $this->request->getPostFile('test_3'));

        // Test with a PostFile
        $visitor->visit($this->command, $this->request, null, new PostFile('baz', __FILE__));
        $this->assertInternalType('array', $this->request->getPostFile('baz'));
    }
}
