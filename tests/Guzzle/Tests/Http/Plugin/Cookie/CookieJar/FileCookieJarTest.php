<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Plugin\Cookie\CookieJar;

use Guzzle\Guzzle;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Plugin\Cookie\CookieJar\FileCookieJar;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class FileCookieJarTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var FileCookieJar
     */
    private $jar;

    /**
     * @var string
     */
    private $file;

    public function setUp()
    {
        $this->file = tempnam('/tmp', 'file-cookies');
        $this->jar = new FileCookieJar($this->file);
    }

    /**
     * Add values to the cookiejar
     */
    protected function addCookies()
    {
        $this->jar->save(array(
            'cookies' => array(
                'foo' => 'bar',
                'baz' => 'foobar'
            ),
            'domain' => 'example.com',
            'path' => '/',
            'max_age' => '86400',
            'port' => array(80, 8080),
            'version' => '1',
            'secure' => true
        ))->save(array(
            'cookies' => array(
                'test' => '123'
            ),
            'domain' => 'www.foobar.com',
            'path' => '/path/',
            'discard' => true
        ))->save(array(
            'domain' => '.y.example.com',
            'path' => '/acme/',
            'cookies' => array(
                'muppet' => 'cookie_monster'
            ),
            'comment' => 'Comment goes here...',
            'expires' => Guzzle::getHttpDate('+1 day')
        ))->save(array(
            'domain' => '.example.com',
            'path' => '/test/acme/',
            'cookies' => array(
                'googoo' => 'gaga'
            ),
            'max_age' => 1500,
            'version' => 2
        ));
    }

    /**
     * @covers Guzzle\Http\Plugin\Cookie\CookieJar\FileCookieJar
     */
    public function testLoadsFromFileFile()
    {
        unset($this->jar);
        $this->jar = new FileCookieJar($this->file);
        $this->assertEquals(array(), $this->jar->getCookies());
        unlink($this->file);
    }

    /**
     * @covers Guzzle\Http\Plugin\Cookie\CookieJar\FileCookieJar
     */
    public function testPersistsToFileFile()
    {
        unset($this->jar);
        $this->jar = new FileCookieJar($this->file);
        $this->addCookies();
        $this->assertEquals(4, count($this->jar->getCookies()));
        unset($this->jar);

        // Make sure it wrote to the file
        $contents = file_get_contents($this->file);
        $this->assertNotEmpty($contents);

        // Load the jar from the file
        $jar = new FileCookieJar($this->file);
        $this->assertEquals(4, count($jar->getCookies()));
        unset($jar);
        unlink($this->file);
    }
}