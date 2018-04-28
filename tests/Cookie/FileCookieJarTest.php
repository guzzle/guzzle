<?php
namespace GuzzleHttp\Tests\CookieJar;

use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\TestCase;

/**
 * @covers GuzzleHttp\Cookie\FileCookieJar
 */
class FileCookieJarTest extends TestCase
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

    public function testLoadsFromFile()
    {
        $jar = new FileCookieJar($this->file);
        $this->assertEquals([], $jar->getIterator()->getArrayCopy());
        unlink($this->file);
    }

    /**
     * @dataProvider testPersistsToFileFileParameters
     */
    public function testPersistsToFile($testSaveSessionCookie = false)
    {
        $jar = new FileCookieJar($this->file, $testSaveSessionCookie);
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

        $this->assertCount(3, $jar);
        unset($jar);

        // Make sure it wrote to the file
        $contents = file_get_contents($this->file);
        $this->assertNotEmpty($contents);

        // Load the cookieJar from the file
        $jar = new FileCookieJar($this->file);

        if ($testSaveSessionCookie) {
            $this->assertCount(3, $jar);
        } else {
            // Weeds out temporary and session cookies
            $this->assertCount(2, $jar);
        }

        unset($jar);
        unlink($this->file);
    }

    public function testPersistsToFileFileParameters()
    {
        return array(
            array(false),
            array(true)
        );
    }
}
