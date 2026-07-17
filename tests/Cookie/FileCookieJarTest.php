<?php

namespace GuzzleHttp\Tests\Cookie;

use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Cookie\FileCookieJar
 */
class FileCookieJarTest extends TestCase
{
    private $file;

    public function setUp(): void
    {
        $this->file = \tempnam(\sys_get_temp_dir(), 'file-cookies');
    }

    public function tearDown(): void
    {
        if (\file_exists($this->file)) {
            \unlink($this->file);
        }
    }

    /**
     * @dataProvider invalidCookieJarContent
     */
    public function testValidatesCookieFile($invalidCookieJarContent)
    {
        \file_put_contents($this->file, json_encode($invalidCookieJarContent));

        $this->expectException(\RuntimeException::class);
        new FileCookieJar($this->file);
    }

    public function testLoadsFromFile()
    {
        $jar = new FileCookieJar($this->file);
        self::assertSame([], $jar->getIterator()->getArrayCopy());
    }

    /**
     * @dataProvider providerPersistsToFileFileParameters
     */
    public function testPersistsToFile($testSaveSessionCookie = false)
    {
        $jar = new FileCookieJar($this->file, $testSaveSessionCookie);
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

        // Make sure it wrote to the file
        $contents = \file_get_contents($this->file);
        self::assertNotEmpty($contents);

        // Load the cookieJar from the file
        $jar = new FileCookieJar($this->file);

        if ($testSaveSessionCookie) {
            self::assertCount(3, $jar);
        } else {
            // Weeds out temporary and session cookies
            self::assertCount(2, $jar);
        }

        unset($jar);
    }

    public function testPersistsCookieWithoutDomain(): void
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Expires' => \time() + 1000,
        ]));
        $jar->save($this->file);

        $reloaded = new FileCookieJar($this->file);
        $cookie = $reloaded->getCookieByName('foo');

        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertNull($cookie->getDomain());
        self::assertFalse($cookie->getHostOnly());

        unset($jar, $reloaded);
    }

    public function testPersistsHostOnlyCookie(): void
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => 'example.com',
            'HostOnly' => true,
            'Expires' => \time() + 1000,
        ]));
        $jar->save($this->file);

        $reloaded = new FileCookieJar($this->file);
        $cookie = $reloaded->getCookieByName('foo');

        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertTrue($cookie->getHostOnly());

        unset($jar, $reloaded);
    }

    public function testLoadDoesNotChangeJarWhenLaterRecordLacksHostOnlyMarker(): void
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'existing',
            'Value' => 'cookie',
            'Domain' => 'example.com',
        ]));
        $source = $this->file.'.load';

        try {
            \file_put_contents($source, '[{"Name":"loaded","Value":"cookie","Domain":"example.com","HostOnly":false},{"Name":"invalid","Value":"cookie","Domain":"example.com"}]');

            try {
                $jar->load($source);
                self::fail('Expected RuntimeException was not thrown');
            } catch (\RuntimeException $e) {
                self::assertSame("Invalid cookie file: {$source}", $e->getMessage());
            }

            self::assertCount(1, $jar);
            self::assertInstanceOf(SetCookie::class, $jar->getCookieByName('existing'));
            self::assertNull($jar->getCookieByName('loaded'));
        } finally {
            if (\file_exists($source)) {
                \unlink($source);
            }
        }
    }

    public function testRemovesCookie()
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));

        self::assertCount(1, $jar);

        // Remove the cookie.
        $jar->clear('foo.com', '/', 'foo');

        // Confirm that the cookie was removed.
        self::assertCount(0, $jar);
    }

    public function testUpdatesCookie()
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));

        self::assertCount(1, $jar);

        // Update the cookie value.
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'new_value',
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));

        $cookies = $jar->getIterator()->getArrayCopy();

        // Confirm that the cookie was updated.
        self::assertEquals('new_value', $cookies[0]->getValue());
    }

    public static function providerPersistsToFileFileParameters()
    {
        return [
            [false],
            [true],
        ];
    }

    public static function invalidCookieJarContent(): array
    {
        return [
            [true],
            ['invalid-data'],
            [[['Name' => 'foo']]],
            [[['HostOnly' => 'false']]],
        ];
    }
}
