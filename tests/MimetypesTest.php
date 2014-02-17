<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Mimetypes;

/**
 * @covers GuzzleHttp\Mimetypes
 */
class MimetypesTest extends \PHPUnit_Framework_TestCase
{
    public function testGetsFromExtension()
    {
        $this->assertEquals('text/x-php', Mimetypes::getInstance()->fromExtension('php'));
    }

    public function testGetsFromFilename()
    {
        $this->assertEquals('text/x-php', Mimetypes::getInstance()->fromFilename(__FILE__));
    }

    public function testGetsFromCaseInsensitiveFilename()
    {
        $this->assertEquals('text/x-php', Mimetypes::getInstance()->fromFilename(strtoupper(__FILE__)));
    }

    public function testReturnsNullWhenNoMatchFound()
    {
        $this->assertNull(Mimetypes::getInstance()->fromExtension('foobar'));
    }
}
