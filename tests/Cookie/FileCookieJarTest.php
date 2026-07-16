<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Cookie;

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
     */
    public function testRejectsInvalidCookieFile(string $contents): void
    {
        \file_put_contents($this->file, $contents);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid cookie file: {$this->file}");
        new FileCookieJar($this->file);
    }

    public function testLoadsEmptyFile(): void
    {
        $jar = new FileCookieJar($this->file);
        self::assertSame([], $jar->getIterator()->getArrayCopy());
    }

    public function testLoadsEmptyJsonList(): void
    {
        \file_put_contents($this->file, " \n[]");

        $jar = new FileCookieJar($this->file);
        self::assertSame([], $jar->getIterator()->getArrayCopy());
    }

    public function testLoadMergesCookiesUsingExistingValidation(): void
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'existing',
            'Value' => 'cookie',
            'Domain' => 'example.com',
        ]));
        \file_put_contents($this->file, '[{},{"Name":"loaded","Value":"cookie","Domain":"example.com"}]');

        $jar->load($this->file);

        self::assertInstanceOf(SetCookie::class, $jar->getCookieByName('existing'));
        self::assertInstanceOf(SetCookie::class, $jar->getCookieByName('loaded'));
    }

    public function testLoadDoesNotChangeJarWhenLaterRecordIsInvalid(): void
    {
        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'existing',
            'Value' => 'cookie',
            'Domain' => 'example.com',
        ]));
        $cookies = $jar->toArray();
        $source = $this->file.'.load';

        try {
            \file_put_contents($source, '[{"Name":"loaded","Value":"cookie","Domain":"example.com"},{"Name":false,"Value":"invalid"}]');

            try {
                $jar->load($source);
                self::fail('Expected RuntimeException was not thrown');
            } catch (\RuntimeException $e) {
                self::assertSame("Invalid cookie file: {$source}", $e->getMessage());
            }

            self::assertSame($cookies, $jar->toArray());
        } finally {
            if (\file_exists($source)) {
                \unlink($source);
            }
        }
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
        FileCookieJarStringableMarker::$calls = 0;
        \file_put_contents($this->file, '');

        try {
            \unserialize(self::serializedObjectWithProperties(FileCookieJar::class, [
                self::privateProperty(FileCookieJar::class, 'filename') => self::serializedObject(FileCookieJarTestStringable::class),
            ]), ['allowed_classes' => [FileCookieJar::class, FileCookieJarTestStringable::class]]);
            self::fail('Expected unserialization to fail.');
        } catch (\LogicException $e) {
            self::assertSame(FileCookieJarTestStringable::class.' blocked unserialization', $e->getMessage());
        }

        self::assertStringEqualsFile($this->file, '');
        self::assertSame(0, FileCookieJarStringableMarker::$calls);
    }

    public function testSavesCookieFileWithOwnerOnlyPermissions(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX file permissions are not enforced on Windows');
        }

        // Start from a world-readable file to prove save() restricts it.
        \chmod($this->file, 0644);
        \clearstatcache(true, $this->file);
        self::assertSame(0644, \fileperms($this->file) & 0777);

        $jar = new FileCookieJar($this->file);
        $jar->setCookie(new SetCookie([
            'Name' => 'foo',
            'Value' => 'bar',
            'Domain' => 'foo.com',
            'Expires' => \time() + 1000,
        ]));
        $jar->save($this->file);

        \clearstatcache(true, $this->file);
        self::assertSame(0600, \fileperms($this->file) & 0777);
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

    public function testRejectsNativePhpUnserialization(): void
    {
        $class = FileCookieJar::class;

        try {
            \unserialize(self::serializedObject($class), ['allowed_classes' => [$class]]);
            self::fail('Expected unserialization to fail.');
        } catch (\LogicException $e) {
            self::assertSame($class.' should never be unserialized', $e->getMessage());
        }
    }

    public function testRejectsNativePhpUnserializationWithRuntimeClassName(): void
    {
        $class = FileCookieJarSerializationTestDouble::class;

        try {
            \unserialize(self::serializedObject($class), ['allowed_classes' => [$class]]);
            self::fail('Expected unserialization to fail.');
        } catch (\LogicException $e) {
            self::assertSame($class.' should never be unserialized', $e->getMessage());
        }
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
            'malformed JSON' => ['['],
            'non-list root' => ['null'],
            'numeric-keyed object root' => ['{"0":{"Name":"foo","Value":"bar"}}'],
            'non-array record' => ['[1]'],
            'invalid field type' => ['[{"Name":false,"Value":"bar"}]'],
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

final class FileCookieJarTestStringable
{
    public function __unserialize(array $data): void
    {
        throw new \LogicException(self::class.' blocked unserialization');
    }

    public function __toString(): string
    {
        ++FileCookieJarStringableMarker::$calls;

        return 'blocked';
    }
}

final class FileCookieJarStringableMarker
{
    public static int $calls = 0;
}

final class FileCookieJarSerializationTestDouble extends FileCookieJar
{
}
