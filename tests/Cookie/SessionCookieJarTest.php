<?php

namespace GuzzleHttp\Tests\CookieJar;

use GuzzleHttp\Cookie\SessionCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Cookie\SessionCookieJar
 */
class SessionCookieJarTest extends TestCase
{
    private $sessionVar;

    public function setUp(): void
    {
        $this->sessionVar = 'sessionKey';

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    public function testValidatesCookieSession()
    {
        $_SESSION[$this->sessionVar] = 'true';

        $this->expectException(\RuntimeException::class);
        new SessionCookieJar($this->sessionVar);
    }

    public function testLoadsFromSession()
    {
        $jar = new SessionCookieJar($this->sessionVar);
        self::assertSame([], $jar->getIterator()->getArrayCopy());
        unset($_SESSION[$this->sessionVar]);
    }

    /**
     * @dataProvider providerPersistsToSessionParameters
     */
    public function testPersistsToSession($testSaveSessionCookie = false)
    {
        $jar = new SessionCookieJar($this->sessionVar, $testSaveSessionCookie);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));
        $jar->setCookie(new SetCookie([
            'Name' => 'baz',
            'Value' => 'bar',
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));
        $jar->setCookie(new SetCookie([
            'Name' => 'boo',
            'Value' => 'bar',
            'Domain' => 'foo.com',
        ]));

        self::assertCount(3, $jar);
        unset($jar);

        // Make sure it wrote to the sessionVar in $_SESSION
        $contents = $_SESSION[$this->sessionVar];
        self::assertNotEmpty($contents);

        // Load the cookieJar from the file
        $jar = new SessionCookieJar($this->sessionVar);

        if ($testSaveSessionCookie) {
            self::assertCount(3, $jar);
        } else {
            // Weeds out temporary and session cookies
            self::assertCount(2, $jar);
        }

        unset($jar);
        unset($_SESSION[$this->sessionVar]);
    }

    public static function providerPersistsToSessionParameters()
    {
        return [
            [false],
            [true],
        ];
    }
}
