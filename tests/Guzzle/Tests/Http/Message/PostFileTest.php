<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\PostFile;

/**
 * @covers Guzzle\Http\Message\PostFile
 */
class PostFileTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testConstructorConfiguresPostFile()
    {
        $file = new PostFile('foo', __FILE__, 'x-foo');
        $this->assertEquals('foo', $file->getFieldName());
        $this->assertEquals(__FILE__, $file->getFilename());
        $this->assertEquals('x-foo', $file->getContentType());
    }

    public function testRemovesLeadingAtSymbolFromPath()
    {
        $file = new PostFile('foo', '@' . __FILE__);
        $this->assertEquals(__FILE__, $file->getFilename());
    }

    /**
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testEnsuresFileIsReadable()
    {
        $file = new PostFile('foo', '/foo/baz/bar');
    }

    public function testCanChangeContentType()
    {
        $file = new PostFile('foo', '@' . __FILE__);
        $file->setContentType('Boo');
        $this->assertEquals('Boo', $file->getContentType());
    }

    public function testCanChangeFieldName()
    {
        $file = new PostFile('foo', '@' . __FILE__);
        $file->setFieldName('Boo');
        $this->assertEquals('Boo', $file->getFieldName());
    }

    public function testReturnsCurlValueString()
    {
        $file = new PostFile('foo', __FILE__);
        $this->assertEquals('@' . __FILE__ . ';type=text/x-php', $file->getCurlString());
    }
}
