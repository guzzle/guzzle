<?php

namespace Guzzle\Tests\Http\CookieJar;

use Guzzle\Http\CookieJar\FileCookieJar;
use Guzzle\Http\CookieJar\SetCookie;

/**
 * @covers Guzzle\Http\CookieJar\FileCookieJar
 */
class FileCookieJarTest extends \PHPUnit_Framework_TestCase
{
    private $file;

    public function setUp()
    {
        $this->file = tempnam('/tmp', 'file-cookies');
    }

    public function testLoadsFromFileFile()
    {
        $jar = new FileCookieJar($this->file);
        $this->assertEquals(array(), $jar->all());
        unlink($this->file);
    }

    public function testPersistsToFileFile()
    {
        $jar = new FileCookieJar($this->file);
        $jar->add(new SetCookie(array(
            'Name'    => 'foo',
            'Value'   => 'bar',
            'Domain'  => 'foo.com',
            'Expires' => time() + 1000
        )));
        $jar->add(new SetCookie(array(
            'Name'    => 'baz',
            'Value'   => 'bar',
            'Domain'  => 'foo.com',
            'Expires' => time() + 1000
        )));
        $jar->add(new SetCookie(array(
            'Name'    => 'boo',
            'Value'   => 'bar',
            'Domain'  => 'foo.com',
        )));

        $this->assertEquals(3, count($jar));
        unset($jar);

        // Make sure it wrote to the file
        $contents = file_get_contents($this->file);
        $this->assertNotEmpty($contents);

        // Load the cookieJar from the file
        $jar = new FileCookieJar($this->file);

        // Weeds out temporary and session cookies
        $this->assertEquals(2, count($jar));
        unset($jar);
        unlink($this->file);
    }
}
