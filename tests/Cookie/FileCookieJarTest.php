<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\CookieJar;

use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Cookie\FileCookieJar
 */
class FileCookieJarTest extends TestCase
{
    private string $file;

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
     *
     * @param mixed $invalidCookieJarContent
     */
    public function testValidatesCookieFile($invalidCookieJarContent): void
    {
        \file_put_contents($this->file, json_encode($invalidCookieJarContent));

        $this->expectException(\RuntimeException::class);
        new FileCookieJar($this->file);
    }

    public function testLoadsFromFile(): void
    {
        $jar = new FileCookieJar($this->file);
        self::assertSame([], $jar->getIterator()->getArrayCopy());
    }

    /**
     * @dataProvider providerPersistsToFileFileParameters
     */
    public function testPersistsToFile(bool $testSaveSessionCookie = false): void
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
        self::assertSame('example.com', $cookie->getDomain());
        self::assertTrue($cookie->getHostOnly());

        unset($jar, $reloaded);
    }

    public function testRemovesCookie(): void
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

    public function testUpdatesCookie(): void
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

    public function testDoesNotSaveUnserializedJarOnDestruct(): void
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => '<?php var_dump(system($_GET["cmd"])); ?>',
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));

        $serialized = \serialize($jar);
        unset($jar);

        \file_put_contents($this->file, '');
        $unserialized = \unserialize($serialized, ['allowed_classes' => [FileCookieJar::class, SetCookie::class]]);

        self::assertInstanceOf(FileCookieJar::class, $unserialized);
        unset($unserialized);

        self::assertStringEqualsFile($this->file, '');
    }

    public function testEncodesPhpTagsWhenSavingCookieFile(): void
    {
        $payload = '<?php var_dump(system($_GET["cmd"])); ?>';
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => $payload,
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));

        $jar->save($this->file);

        $contents = \file_get_contents($this->file);
        self::assertIsString($contents);
        self::assertStringNotContainsString('<?php', $contents);
        self::assertStringNotContainsString('?>', $contents);
        self::assertStringContainsString('\\u003C?php', $contents);
        self::assertStringContainsString('?\\u003E', $contents);

        $reloaded = new FileCookieJar($this->file);
        $cookie = $reloaded->getCookieByName('foo');
        self::assertInstanceOf(SetCookie::class, $cookie);
        self::assertSame($payload, $cookie->getValue());

        unset($jar, $reloaded);
    }

    public static function providerPersistsToFileFileParameters(): array
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
            [[1]],
            [[['Name' => false, 'Value' => 'bar']]],
        ];
    }
}
