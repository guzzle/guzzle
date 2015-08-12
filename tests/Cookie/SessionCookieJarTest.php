<?php
namespace GuzzleHttp\Tests\CookieJar;

use GuzzleHttp\Cookie\SessionCookieJar;
use GuzzleHttp\Cookie\SetCookie;

/**
 * @covers GuzzleHttp\Cookie\SessionCookieJar
 */
class SessionCookieJarTest extends \PHPUnit_Framework_TestCase
{
    private $sessionVar;

    public function setUp()
    {
        $this->sessionVar = 'sessionKey';

        if (!isset($_SESSION)) {
            $_SESSION = array();
        }
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testValidatesCookieSession()
    {
        $_SESSION[$this->sessionVar] = 'true';
        new SessionCookieJar($this->sessionVar);
    }

    public function testLoadsFromSession()
    {
        $jar = new SessionCookieJar($this->sessionVar);
        $this->assertEquals([], $jar->getIterator()->getArrayCopy());
        unset($_SESSION[$this->sessionVar]);
    }

    /**
     * @dataProvider testPersistsToSessionParameters
     */
    public function testPersistsToSession($testSaveSessionCookie = false)
    {
        $jar = new SessionCookieJar($this->sessionVar, $testSaveSessionCookie);
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

        // Make sure it wrote to the sessionVar in $_SESSION
        $contents = $_SESSION[$this->sessionVar];
        $this->assertNotEmpty($contents);

        // Load the cookieJar from the file
        $jar = new SessionCookieJar($this->sessionVar);

        if ($testSaveSessionCookie) {
            $this->assertEquals(3, count($jar));
        } else {
            // Weeds out temporary and session cookies
            $this->assertEquals(2, count($jar));
        }

        unset($jar);
        unset($_SESSION[$this->sessionVar]);
    }

    public function testPersistsToSessionParameters()
    {
        return array(
            array(false),
            array(true)
        );
    }
}
