<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\ClientTrait;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\RequestFraming;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

class SensitiveParameterTest extends TestCase
{
    private const ATTRIBUTE = '#[\\SensitiveParameter]';

    public function testSourceInventoryMatchesReviewedExecutableManifest(): void
    {
        $expected = self::expectedInventory();
        $actual = [];
        $attributeCount = 0;
        $declarationCount = 0;
        $fileCount = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__).'/src')
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            $count = substr_count($contents, self::ATTRIBUTE);
            if ($count === 0) {
                continue;
            }

            ++$fileCount;
            $attributeCount += $count;
            $relative = 'src/'.str_replace('\\', '/', substr(
                $file->getPathname(),
                \strlen(dirname(__DIR__).'/src/')
            ));
            $actual[$relative] = self::selectedDeclarations($contents);
            $declarationCount += \count($actual[$relative]);

            preg_match_all(
                '/^[ \t]*#\[\\\\SensitiveParameter\][ \t]*$/m',
                $contents,
                $isolatedAttributes
            );
            self::assertCount(
                $count,
                $isolatedAttributes[0],
                $relative.' contains an inline SensitiveParameter attribute'
            );
        }

        self::assertSame(317, $attributeCount);
        self::assertSame(210, $declarationCount);
        self::assertSame(24, $fileCount);
        self::assertSame(self::normalizedInventory($expected), self::normalizedInventory($actual));
    }

    public function testEveryNamedManifestParameterIsReflected(): void
    {
        if (PHP_VERSION_ID < 80000) {
            self::markTestSkipped('PHP 7.4 parses the attribute lines as comments.');
        }

        foreach (self::expectedInventory() as $declarations) {
            foreach ($declarations as $symbol => $expectedParameters) {
                if (strpos($symbol, '::') === false) {
                    continue;
                }

                [$class, $method] = explode('::', $symbol, 2);
                $selected = [];
                foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
                    $attributes = $parameter->getAttributes(\SensitiveParameter::class);
                    if ($attributes === []) {
                        continue;
                    }

                    self::assertCount(1, $attributes, $symbol.'::$'.$parameter->getName());
                    self::assertInstanceOf(\SensitiveParameter::class, $attributes[0]->newInstance());
                    $selected[] = $parameter->getName();
                }

                sort($selected);
                sort($expectedParameters);
                self::assertSame($expectedParameters, $selected, $symbol);
            }
        }
    }

    public function testInterfacesAndAbstractTraitMethodsRemainUnannotated(): void
    {
        if (PHP_VERSION_ID < 80000) {
            self::markTestSkipped('PHP 7.4 parses the attribute lines as comments.');
        }

        $methods = [
            [ClientInterface::class, 'send'],
            [ClientInterface::class, 'sendAsync'],
            [ClientInterface::class, 'request'],
            [ClientInterface::class, 'requestAsync'],
            [CurlFactoryInterface::class, 'create'],
            [CurlFactoryInterface::class, 'release'],
            [CookieJarInterface::class, 'withCookieHeader'],
            [CookieJarInterface::class, 'extractCookies'],
            [CookieJarInterface::class, 'setCookie'],
            [ClientTrait::class, 'request'],
            [ClientTrait::class, 'requestAsync'],
        ];

        foreach ($methods as [$class, $method]) {
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
                self::assertSame([], $parameter->getAttributes(\SensitiveParameter::class));
            }
        }
    }

    public function testOptionsAreRedactedInEveryActiveValidationFrame(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('Native trace argument redaction requires PHP 8.2.');
        }

        $previous = self::enableTraceArguments();
        try {
            try {
                (new Client())->request('GET', 'https://example.com', [
                    'headers' => [
                        'Authorization' => 'Bearer secret-header-sentinel',
                        'Invalid' => new \stdClass(),
                    ],
                ]);
                self::fail('Expected invalid header value to throw.');
            } catch (\Throwable $exception) {
                foreach ([
                    ['assertHeaderOptionTypes', 0],
                    ['assertRequestOptionTypes', 0],
                    ['prepareDefaults', 0],
                    ['requestAsync', 2],
                    ['request', 2],
                ] as [$function, $position]) {
                    $frame = self::findFrame($exception, Client::class, $function);
                    self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][$position]);
                }

                $requestFrame = self::findFrame($exception, Client::class, 'request');
                self::assertSame('GET', $requestFrame['args'][0]);
            }
        } finally {
            self::restoreTraceArguments($previous);
        }
    }

    public function testReturnedExceptionCapturesRedactedFactoryArguments(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('Native trace argument redaction requires PHP 8.2.');
        }

        $previousSetting = self::enableTraceArguments();
        try {
            $source = new \RuntimeException('source failure');
            $method = new \ReflectionMethod(RequestFraming::class, 'bodyException');
            $method->setAccessible(true);
            $exception = $method->invoke(
                null,
                new Request('POST', 'https://example.com'),
                $source,
                'timeout',
                'fallback'
            );
            self::assertInstanceOf(RequestException::class, $exception);

            $frame = self::findFrame($exception, RequestFraming::class, 'bodyException');
            self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0]);
            self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][1]);
            self::assertSame('timeout', $frame['args'][2]);
            self::assertSame('fallback', $frame['args'][3]);
        } finally {
            self::restoreTraceArguments($previousSetting);
        }
    }

    public function testVariadicTraceRedactionMatchesTheRuntimeVersion(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('Native trace argument redaction requires PHP 8.2.');
        }

        $previous = self::enableTraceArguments();
        try {
            $handler = new MockHandler();
            try {
                $handler->append('positional-secret');
                self::fail('Expected invalid queue value to throw.');
            } catch (\TypeError $exception) {
                $frame = self::findFrame($exception, MockHandler::class, 'append');
                self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0]);
            }

            try {
                $handler->append(...['named' => 'named-secret']);
                self::fail('Expected invalid queue value to throw.');
            } catch (\TypeError $exception) {
                $frame = self::findFrame($exception, MockHandler::class, 'append');
                if (PHP_VERSION_ID < 80300) {
                    self::assertSame('named-secret', $frame['args']['named']);
                } else {
                    self::assertInstanceOf(
                        \SensitiveParameterValue::class,
                        $frame['args']['named']
                    );
                }
            }
        } finally {
            self::restoreTraceArguments($previous);
        }
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private static function expectedInventory(): array
    {
        return require __DIR__.'/fixtures/sensitive_parameters.php';
    }

    /**
     * @return array<string, list<string>>
     */
    private static function selectedDeclarations(string $contents): array
    {
        $tokens = token_get_all($contents);
        $selectedDeclarations = [];
        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $cursor = $index + 1;
            while (isset($tokens[$cursor])
                && ((\is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE)
                    || self::tokenText($tokens[$cursor]) === '&')
            ) {
                ++$cursor;
            }
            $name = isset($tokens[$cursor])
                && \is_array($tokens[$cursor])
                && $tokens[$cursor][0] === T_STRING
                ? $tokens[$cursor][1]
                : null;
            while (isset($tokens[$cursor]) && $tokens[$cursor] !== '(') {
                ++$cursor;
            }
            self::assertArrayHasKey($cursor, $tokens);

            $depth = 0;
            $segment = '';
            $segments = [];
            $finalComma = false;
            for (++$cursor, $length = \count($tokens); $cursor < $length; ++$cursor) {
                $part = $tokens[$cursor];
                if ((\is_array($part) && \defined('T_ATTRIBUTE') && $part[0] === T_ATTRIBUTE)
                    || $part === '(' || $part === '[' || $part === '{'
                ) {
                    ++$depth;
                } elseif ($part === ')' && $depth === 0) {
                    $segments[] = $segment;
                    break;
                } elseif ($part === ')' || $part === ']' || $part === '}') {
                    --$depth;
                }

                if ($part === ',' && $depth === 0) {
                    $segments[] = $segment;
                    $segment = '';
                    $finalComma = true;
                    continue;
                }
                if ($depth === 0 && trim(self::tokenText($part), " \t\n\r\0\x0B") !== '') {
                    $finalComma = false;
                }
                $segment .= self::tokenText($part);
            }

            $selected = [];
            foreach ($segments as $parameter) {
                $count = substr_count($parameter, self::ATTRIBUTE);
                if ($count === 0) {
                    continue;
                }
                self::assertSame(1, $count);
                self::assertSame(1, preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $parameter, $matches));
                $selected[] = $matches[1];
            }
            if ($selected === []) {
                continue;
            }

            self::assertFalse($finalComma, 'Selected declaration has a final parameter comma.');
            $symbol = $name ?? 'closure@'.$token[2];
            self::assertArrayNotHasKey($symbol, $selectedDeclarations);
            $selectedDeclarations[$symbol] = $selected;
        }

        return $selectedDeclarations;
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * @param array<string, array<string, list<string>>> $inventory
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function normalizedInventory(array $inventory): array
    {
        foreach ($inventory as &$declarations) {
            $normalized = [];
            foreach ($declarations as $symbol => $parameters) {
                if (strpos($symbol, '::') !== false) {
                    $symbol = substr($symbol, strrpos($symbol, '::') + 2);
                }
                sort($parameters);
                $normalized[$symbol] = $parameters;
            }
            ksort($normalized);
            $declarations = $normalized;
        }
        unset($declarations);
        ksort($inventory);

        return $inventory;
    }

    /**
     * @return array<string, mixed>
     */
    private static function findFrame(\Throwable $exception, string $class, string $function): array
    {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === $class && $frame['function'] === $function) {
                return $frame;
            }
        }

        self::fail("Trace does not contain {$class}::{$function}().");
    }

    /**
     * @return string|false Previous setting.
     */
    private static function enableTraceArguments()
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        if (ini_get('zend.exception_ignore_args') !== '0') {
            self::markTestSkipped('The runtime does not permit exception trace arguments.');
        }

        return $previous;
    }

    /**
     * @param string|false $previous
     */
    private static function restoreTraceArguments($previous): void
    {
        if ($previous !== false) {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
