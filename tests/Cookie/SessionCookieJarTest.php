<?php

namespace GuzzleHttp\Tests\Cookie;

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

        unset($_SESSION[$this->sessionVar]);
    }

    public function testValidatesCookieSession()
    {
        $_SESSION[$this->sessionVar] = 'true';

        $this->expectException(\RuntimeException::class);
        new SessionCookieJar($this->sessionVar);
    }

    /**
     * @dataProvider invalidCookieSessionProvider
     *
     * @param mixed $sessionData
     */
    public function testValidatesMalformedCookieSession($sessionData)
    {
        $_SESSION[$this->sessionVar] = $sessionData;

        $this->expectException(\RuntimeException::class);
        new SessionCookieJar($this->sessionVar);
    }

    public function testValidatesCookieSessionJsonEncoding(): void
    {
        $jar = new SessionCookieJar($this->sessionVar, true);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => "\x99",
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));

        try {
            $jar->save();
            self::fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('Unable to encode cookie data', $e->getMessage());
        } finally {
            $jar->clear();
            unset($jar, $_SESSION[$this->sessionVar]);
        }
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

    public function testPersistsCookieWithoutDomain(): void
    {
        $jar = new SessionCookieJar($this->sessionVar);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Expires' => \time() + 1000,
        ]));
        $jar->save();

        $reloaded = new SessionCookieJar($this->sessionVar);
        $cookie = $reloaded->getCookieByName('foo');

        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertNull($cookie->getDomain());

        unset($jar, $reloaded, $_SESSION[$this->sessionVar]);
    }

    public static function providerPersistsToSessionParameters()
    {
        return [
            [false],
            [true],
        ];
    }

    public static function invalidCookieSessionProvider(): array
    {
        return [
            [[]],
            [new \stdClass()],
            ['[1]'],
        ];
    }
}
