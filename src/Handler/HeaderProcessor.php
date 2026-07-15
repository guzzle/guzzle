<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Psr7;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class HeaderProcessor
{
    private function __construct()
    {
    }

    /**
     * Returns the HTTP version, status code, reason phrase, and headers.
     *
     * @param string[] $headers
     *
     * @return array{0:string, 1:int, 2:?string, 3:array}
     *
     * @throws \RuntimeException
     */
    public static function parseHeaders(array $headers): array
    {
        if ($headers === []) {
            throw new \RuntimeException('Expected a non-empty array of header data');
        }

        $headers = self::getLastHeaderBlock(\array_values($headers));

        $statusLine = \array_shift($headers);
        if ($statusLine === null) {
            throw new \RuntimeException('Expected a non-empty array of header data');
        }

        $parts = \explode(' ', $statusLine, 3);
        $protocol = $parts[0];

        if (!Psr7\Utils::caselessEquals(\substr($protocol, 0, 5), 'HTTP/')) {
            throw new \RuntimeException('HTTP version missing from header data');
        }

        $version = \substr($protocol, 5);

        if (!\preg_match('/^\d+(?:\.\d+)?$/D', $version)) {
            throw new \RuntimeException('HTTP version is invalid');
        }

        $status = $parts[1] ?? null;

        if ($status === null) {
            throw new \RuntimeException('HTTP status code missing from header data');
        }

        if (!\preg_match('/^[1-5]\d{2}$/D', $status)) {
            throw new \RuntimeException('HTTP status code is invalid');
        }

        $reason = $parts[2] ?? null;

        if ($reason !== null && !\preg_match('/^[\x09\x20-\x7E\x80-\xFF]*$/D', $reason)) {
            throw new \RuntimeException('HTTP reason phrase is invalid');
        }

        foreach ($headers as $header) {
            if (\strpos($header, ':') === false) {
                throw new \RuntimeException('HTTP header line is invalid');
            }
        }

        return [$version, (int) $status, $reason, Utils::headersFromLines($headers)];
    }

    public static function isStatusLineCandidate(string $line): bool
    {
        return \preg_match('/^HTTP\/[0-9]+(?:\.[0-9]+)? [0-9]{3}(?: [^\r\n]*)?(?:\r\n|\r|\n)?$/iD', $line) === 1;
    }

    public static function isValidHeaderFieldLine(string $line): bool
    {
        $parts = \explode(':', $line, 2);

        if (!isset($parts[1])) {
            return false;
        }

        if (!\preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/D', $parts[0])) {
            return false;
        }

        return \preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*(?:\r\n|\r|\n)?$/D', \trim($parts[1], " \t")) === 1;
    }

    /**
     * Returns a normalized decimal Content-Length, or null when absent.
     *
     * @param string[] $values
     *
     * @throws \RuntimeException when Content-Length is malformed or conflicting.
     */
    public static function parseContentLength(array $values): ?string
    {
        $length = null;

        foreach ($values as $value) {
            foreach (\explode(',', $value) as $part) {
                $part = \trim($part, " \t");
                if (\preg_match('/^[0-9]+$/D', $part) !== 1) {
                    throw new \RuntimeException('value is not a non-negative decimal integer');
                }

                $part = \ltrim($part, '0');
                $part = $part === '' ? '0' : $part;
                if ($length !== null && $part !== $length) {
                    throw new \RuntimeException('values conflict');
                }

                $length = $part;
            }
        }

        if ($length === null) {
            return null;
        }

        return $length;
    }

    public static function contentLengthToInt(?string $length): ?int
    {
        if ($length === null) {
            return null;
        }

        $max = (string) \PHP_INT_MAX;
        if (
            \strlen($length) > \strlen($max)
            || (\strlen($length) === \strlen($max) && \strcmp($length, $max) > 0)
        ) {
            return null;
        }

        return (int) $length;
    }

    public static function assertContentLengthWithinPlatformLimit(?string $length): void
    {
        if ($length === null || self::contentLengthToInt($length) !== null) {
            return;
        }

        throw new \OverflowException('Content-Length exceeds the maximum integer size supported on this platform');
    }

    public static function parseContentLengthForResponseBody(RequestInterface $request, ResponseInterface $response): ?string
    {
        return self::parseContentLengthForResponseBodyValues(
            $request->getMethod(),
            $response->getStatusCode(),
            $response->hasHeader('Transfer-Encoding'),
            $response->getHeader('Content-Length')
        );
    }

    /**
     * @param array<string, string[]> $headers
     */
    public static function parseContentLengthForResponseBodyHeaders(
        string $method,
        int $status,
        array $headers
    ): ?string {
        $normalizedKeys = Utils::normalizeHeaderKeys($headers);
        $contentLength = self::removeHeader('Content-Length', $headers);

        return self::parseContentLengthForResponseBodyValues(
            $method,
            $status,
            isset($normalizedKeys['transfer-encoding']),
            $contentLength
        );
    }

    /**
     * Removes every case-insensitive occurrence of a header and returns all
     * removed values in their original field order.
     *
     * @param array<string, string[]> $headers
     *
     * @return string[] Removed values across all header-name casings
     */
    public static function removeHeader(string $name, array &$headers): array
    {
        $values = [];

        foreach ($headers as $key => $headerValues) {
            if (Psr7\Utils::caselessEquals((string) $key, $name)) {
                \array_push($values, ...$headerValues);
                unset($headers[$key]);
            }
        }

        return $values;
    }

    /**
     * @param string[] $contentLength
     */
    private static function parseContentLengthForResponseBodyValues(
        string $method,
        int $status,
        bool $hasTransferEncoding,
        array $contentLength
    ): ?string {
        if (!self::responseCanHaveBody($method, $status) || $hasTransferEncoding) {
            return null;
        }

        try {
            return self::parseContentLength($contentLength);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Whether a response to the given request method with the given status
     * code can carry content at all, per RFC 9110: a response to HEAD, a
     * response with a 1xx, 204, or 304 status code, or a 2xx response to
     * CONNECT never has a body, whatever its framing headers claim.
     */
    public static function responseCanHaveBody(string $method, int $status): bool
    {
        return $method !== 'HEAD'
            && !($method === 'CONNECT' && $status >= 200 && $status < 300)
            && $status >= 200
            && $status !== 204
            && $status !== 304;
    }

    /**
     * @param non-empty-list<string> $headers
     *
     * @return list<string>
     */
    private static function getLastHeaderBlock(array $headers): array
    {
        $lastStatusLine = 0;

        foreach ($headers as $index => $line) {
            if (self::isStatusLineCandidate($line)) {
                $lastStatusLine = $index;
            }
        }

        return \array_slice($headers, $lastStatusLine);
    }
}
