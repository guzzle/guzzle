<?php
namespace GuzzleHttp\Tests\CookieJar;

use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Cookie\SetCookie;

/**
 * @covers GuzzleHttp\Cookie\FileCookieJar
 */
class FileCookieJarTest extends \PHPUnit_Framework_TestCase
{
    private $file;

    public function setUp()
    {
        $this->file = tempnam('/tmp', 'file-cookies');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testValidatesCookieFile()
    {
        file_put_contents($this->file, 'true');
        new FileCookieJar($this->file);
    }

    public function testLoadsFromFileFile()
    {
        $jar = new FileCookieJar($this->file);
        $this->assertEquals([], $jar->getIterator()->getArrayCopy());
        unlink($this->file);
    }

    public function testPersistsToFileFile()
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name'    => 'foo',
            'Value'   => 'bar',
            'Domain'  => 'foo.com',
            'Expires' => time() + 1000
        ]));
        $jar->setCookie(new SetCookie([
            'Name'    => 'baz',
            'Value'   => 'bar',
            'Domain'  => 'foo.com',
            'Expires' => time() + 1000
        ]));
        $jar->setCookie(new SetCookie([
            'Name'    => 'boo',
            'Value'   => 'bar',
            'Domain'  => 'foo.com',
        ]));

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
