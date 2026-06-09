<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Cookie;

use GuzzleHttp\Cookie\SessionCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Cookie\SessionCookieJar
 */
class SessionCookieJarTest extends TestCase
{
    private string $sessionVar;

    public function setUp(): void
    {
        $this->sessionVar = 'sessionKey';

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        unset($_SESSION[$this->sessionVar]);
    }

    public function testValidatesCookieSession(): void
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
    public function testValidatesMalformedCookieSession($sessionData): void
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

    public function testLoadsFromSession(): void
    {
        $jar = new SessionCookieJar($this->sessionVar);
        self::assertSame([], $jar->getIterator()->getArrayCopy());
        unset($_SESSION[$this->sessionVar]);
    }

    /**
     * @dataProvider providerPersistsToSessionParameters
     */
    public function testPersistsToSession(bool $testSaveSessionCookie = false): void
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

    public function testPersistsHostOnlyCookie(): void
    {
        $jar = new SessionCookieJar($this->sessionVar);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => 'example.com',
            'HostOnly' => true,
            'Expires' => \time() + 1000,
        ]));
        $jar->save();

        $reloaded = new SessionCookieJar($this->sessionVar);
        $cookie = $reloaded->getCookieByName('foo');

        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame('example.com', $cookie->getDomain());
        self::assertTrue($cookie->getHostOnly());

        unset($jar, $reloaded, $_SESSION[$this->sessionVar]);
    }

    public function testDoesNotSaveUnserializedJarOnDestruct(): void
    {
        SessionCookieJarStringableMarker::$calls = 0;
        unset($_SESSION[$this->sessionVar]);

        try {
            \unserialize(self::serializedObjectWithProperties(SessionCookieJar::class, [
                self::privateProperty(SessionCookieJar::class, 'sessionKey') => self::serializedObject(SessionCookieJarTestStringable::class),
            ]), ['allowed_classes' => [SessionCookieJar::class, SessionCookieJarTestStringable::class]]);
            self::fail('Expected unserialization to fail.');
        } catch (\LogicException $e) {
            self::assertSame(SessionCookieJarTestStringable::class.' blocked unserialization', $e->getMessage());
        }

        self::assertArrayNotHasKey($this->sessionVar, $_SESSION);
        self::assertSame(0, SessionCookieJarStringableMarker::$calls);
    }

    public static function providerPersistsToSessionParameters(): array
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
            ['[{"Name":false,"Value":"bar"}]'],
        ];
    }

    private static function serializedObject(string $class): string
    {
        return sprintf('O:%d:"%s":0:{}', strlen($class), $class);
    }

    private static function privateProperty(string $class, string $property): string
    {
        return "\0".$class."\0".$property;
    }

    /**
     * @param array<string, string> $properties Serialized property values indexed by property name.
     */
    private static function serializedObjectWithProperties(string $class, array $properties): string
    {
        $body = '';
        foreach ($properties as $name => $serializedValue) {
            $body .= \serialize($name).$serializedValue;
        }

        return sprintf('O:%d:"%s":%d:{%s}', strlen($class), $class, count($properties), $body);
    }
}

final class SessionCookieJarTestStringable
{
    public function __unserialize(array $data): void
    {
        throw new \LogicException(self::class.' blocked unserialization');
    }

    public function __toString(): string
    {
        ++SessionCookieJarStringableMarker::$calls;

        return 'blocked';
    }
}

final class SessionCookieJarStringableMarker
{
    public static int $calls = 0;
}
