<?php

declare(strict_types=1);

namespace GuzzleHttp\Auth;

use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class DigestAuth
{
    /**
     * @var array<string, array{hash: string, sess: bool, rank: int, header: string}>
     */
    private const ALGORITHMS = [
        'MD5' => ['hash' => 'md5', 'sess' => false, 'rank' => 10, 'header' => 'MD5'],
        'MD5-SESS' => ['hash' => 'md5', 'sess' => true, 'rank' => 11, 'header' => 'MD5-sess'],
        'SHA-256' => ['hash' => 'sha256', 'sess' => false, 'rank' => 20, 'header' => 'SHA-256'],
        'SHA-256-SESS' => ['hash' => 'sha256', 'sess' => true, 'rank' => 21, 'header' => 'SHA-256-sess'],
        'SHA-512-256' => ['hash' => 'sha512/256', 'sess' => false, 'rank' => 30, 'header' => 'SHA-512-256'],
        'SHA-512-256-SESS' => ['hash' => 'sha512/256', 'sess' => true, 'rank' => 31, 'header' => 'SHA-512-256-sess'],
    ];

    /**
     * @var array<string, true>
     */
    private const DIGEST_CHALLENGE_PARAMETER_NAMES = [
        'realm' => true,
        'domain' => true,
        'nonce' => true,
        'opaque' => true,
        'stale' => true,
        'algorithm' => true,
        'qop' => true,
        'charset' => true,
        'userhash' => true,
    ];

    private function __construct()
    {
    }

    public static function selectChallenge(
        #[\SensitiveParameter]
        ResponseInterface $response
    ): ?DigestChallenge {
        $selected = null;

        foreach ($response->getHeader('WWW-Authenticate') as $header) {
            foreach (self::parseAuthenticateHeader($header) as $challenge) {
                if ($challenge['scheme'] !== 'digest' || $challenge['invalid']) {
                    continue;
                }

                $digest = self::createChallenge($challenge['params']);
                if ($digest === null) {
                    continue;
                }

                if ($selected === null || $digest->algorithm['rank'] > $selected->algorithm['rank']) {
                    $selected = $digest;
                }
            }
        }

        return $selected;
    }

    public static function authorizationHeader(
        #[\SensitiveParameter]
        RequestInterface $request,
        DigestChallenge $challenge,
        string $username,
        #[\SensitiveParameter]
        string $password,
        string $cnonce,
        string $nc = '00000001'
    ): ?string {
        if ($challenge->algorithm['sess'] && $challenge->qop === null) {
            return null;
        }

        $uri = $request->getRequestTarget();
        if ($uri === '') {
            $uri = '/';
        }

        foreach ([$username, $challenge->realm, $challenge->nonce, $uri, $cnonce] as $value) {
            if (!self::isHeaderSafe($value)) {
                return null;
            }
        }

        if ($challenge->opaque !== null && !self::isHeaderSafe($challenge->opaque)) {
            return null;
        }

        if ($challenge->qop !== null && !self::isNonceCount($nc)) {
            return null;
        }

        $hash = $challenge->algorithm['hash'];
        $ha1 = \hash($hash, $username.':'.$challenge->realm.':'.$password);

        if ($challenge->algorithm['sess']) {
            $ha1 = \hash($hash, $ha1.':'.$challenge->nonce.':'.$cnonce);
        }

        $ha2 = \hash($hash, $request->getMethod().':'.$uri);
        $response = $challenge->qop === null
            ? \hash($hash, $ha1.':'.$challenge->nonce.':'.$ha2)
            : \hash($hash, $ha1.':'.$challenge->nonce.':'.$nc.':'.$cnonce.':'.$challenge->qop.':'.$ha2);

        if ($challenge->userhash) {
            $headerUsername = \hash($hash, $username.':'.$challenge->realm);
            if ($headerUsername === false) {
                return null;
            }
        } else {
            $headerUsername = $username;
        }

        $parts = [
            'username='.self::quote($headerUsername),
            'realm='.self::quote($challenge->realm),
            'nonce='.self::quote($challenge->nonce),
            'uri='.self::quote($uri),
            'response='.self::quote($response),
            'algorithm='.$challenge->algorithm['header'],
        ];

        if ($challenge->opaque !== null) {
            $parts[] = 'opaque='.self::quote($challenge->opaque);
        }

        if ($challenge->qop !== null) {
            $parts[] = 'qop='.$challenge->qop;
            $parts[] = 'nc='.$nc;
            $parts[] = 'cnonce='.self::quote($cnonce);
        }

        if ($challenge->userhash) {
            $parts[] = 'userhash=true';
        }

        return 'Digest '.\implode(', ', $parts);
    }

    /**
     * @return list<array{scheme: string, params: array<string, string>, invalid: bool}>
     */
    public static function parseAuthenticateHeader(string $header): array
    {
        $length = \strlen($header);
        $offset = 0;
        $challenges = [];

        while (true) {
            self::skipSeparators($header, $offset, $length);
            if ($offset >= $length) {
                break;
            }

            $scheme = self::readToken($header, $offset, $length);
            if ($scheme === null) {
                break;
            }

            self::skipWhitespace($header, $offset, $length);

            if (self::skipToken68Challenge($header, $offset, $length)) {
                $challenges[] = [
                    'scheme' => Psr7\Utils::asciiToLower($scheme),
                    'params' => [],
                    'invalid' => false,
                ];

                continue;
            }

            $params = [];
            $invalid = false;

            while ($offset < $length) {
                self::skipWhitespace($header, $offset, $length);

                if ($offset < $length && $header[$offset] === ',') {
                    if (self::commaStartsNextChallenge($header, $offset + 1, $length)) {
                        if (Psr7\Utils::caselessEquals($scheme, 'Digest')
                            && self::commaStartsKnownDigestParameterWithoutValue($header, $offset + 1, $length)
                        ) {
                            $invalid = true;
                            break;
                        }

                        ++$offset;
                        break;
                    }

                    ++$offset;
                    continue;
                }

                $name = self::readToken($header, $offset, $length);
                if ($name === null) {
                    if ($offset < $length) {
                        $invalid = true;
                    }

                    break;
                }

                self::skipWhitespace($header, $offset, $length);
                if ($offset >= $length || $header[$offset] !== '=') {
                    $invalid = true;
                    break;
                }

                ++$offset;
                self::skipWhitespace($header, $offset, $length);

                $value = self::readValue($header, $offset, $length);
                if ($value === null) {
                    $invalid = true;
                    break;
                }

                $lowerName = Psr7\Utils::asciiToLower($name);
                if (\array_key_exists($lowerName, $params)) {
                    $invalid = true;
                }

                $params[$lowerName] = $value;

                self::skipWhitespace($header, $offset, $length);
                if ($offset >= $length) {
                    break;
                }

                if ($header[$offset] !== ',') {
                    $invalid = true;
                    break;
                }
            }

            $challenges[] = [
                'scheme' => Psr7\Utils::asciiToLower($scheme),
                'params' => $params,
                'invalid' => $invalid,
            ];
        }

        return $challenges;
    }

    /**
     * @param array<string, string> $params
     */
    private static function createChallenge(array $params): ?DigestChallenge
    {
        if (!isset($params['nonce']) || $params['nonce'] === '') {
            return null;
        }

        if (isset($params['charset']) && !Psr7\Utils::caselessEquals($params['charset'], 'UTF-8')) {
            return null;
        }

        $algorithm = self::algorithm($params['algorithm'] ?? null);
        if ($algorithm === null) {
            return null;
        }

        $qop = self::selectQop($params['qop'] ?? null);
        if ($qop === false) {
            return null;
        }

        if ($algorithm['sess'] && $qop === null) {
            return null;
        }

        $challenge = new DigestChallenge();
        $challenge->algorithm = $algorithm;
        $challenge->realm = $params['realm'] ?? '';
        $challenge->nonce = $params['nonce'];
        $challenge->opaque = $params['opaque'] ?? null;
        if (isset($params['domain'])) {
            $domainAreas = \preg_split('/[ \t]+/', $params['domain']);

            if ($domainAreas === false) {
                throw new \RuntimeException('Unable to split the Digest domain list: '.\preg_last_error_msg());
            }

            $challenge->domain = \array_values(\array_filter($domainAreas));
        } else {
            $challenge->domain = [];
        }
        $challenge->qop = $qop;
        $challenge->stale = isset($params['stale']) && Psr7\Utils::caselessEquals($params['stale'], 'true');
        $challenge->userhash = isset($params['userhash']) && Psr7\Utils::caselessEquals($params['userhash'], 'true');

        return $challenge;
    }

    /**
     * @return array{name: string, hash: string, sess: bool, rank: int, header: string}|null
     */
    private static function algorithm(?string $algorithm): ?array
    {
        $name = Psr7\Utils::asciiToUpper($algorithm ?? 'MD5');

        if (!isset(self::ALGORITHMS[$name])) {
            return null;
        }

        $mapped = self::ALGORITHMS[$name];
        if (!\in_array($mapped['hash'], \hash_algos(), true)) {
            return null;
        }

        return ['name' => $name] + $mapped;
    }

    /**
     * @return string|false|null
     */
    private static function selectQop(?string $qop)
    {
        if ($qop === null) {
            return null;
        }

        $tokens = \array_map(
            static function (string $token): string {
                return Psr7\Utils::asciiToLower(\trim($token, " \t"));
            },
            \explode(',', $qop)
        );

        return \in_array('auth', $tokens, true) ? 'auth' : false;
    }

    private static function quote(string $value): string
    {
        return '"'.\strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
        ]).'"';
    }

    private static function isHeaderSafe(string $value): bool
    {
        return \preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D', $value) === 1;
    }

    private static function isNonceCount(string $nc): bool
    {
        // Nonce counts start at one.
        return $nc !== '00000000' && \preg_match('/^[0-9a-f]{8}$/D', $nc) === 1;
    }

    private static function commaStartsNextChallenge(string $header, int $offset, int $length): bool
    {
        self::skipWhitespace($header, $offset, $length);
        $nextOffset = $offset;
        $token = self::readToken($header, $nextOffset, $length);
        if ($token === null) {
            return false;
        }

        self::skipWhitespace($header, $nextOffset, $length);

        return $nextOffset >= $length || $header[$nextOffset] !== '=';
    }

    private static function commaStartsKnownDigestParameterWithoutValue(string $header, int $offset, int $length): bool
    {
        self::skipWhitespace($header, $offset, $length);
        $name = self::readToken($header, $offset, $length);
        if ($name === null || !isset(self::DIGEST_CHALLENGE_PARAMETER_NAMES[Psr7\Utils::asciiToLower($name)])) {
            return false;
        }

        self::skipWhitespace($header, $offset, $length);

        return $offset >= $length || $header[$offset] !== '=';
    }

    private static function skipToken68Challenge(string $header, int &$offset, int $length): bool
    {
        $cursor = $offset;
        $hasValue = false;
        while ($cursor < $length && self::isToken68Char($header[$cursor])) {
            $hasValue = true;
            ++$cursor;
        }

        while ($cursor < $length && $header[$cursor] === '=') {
            ++$cursor;
        }

        if (!$hasValue) {
            return false;
        }

        $after = $cursor;
        self::skipWhitespace($header, $after, $length);

        if ($after < $length && $header[$after] !== ',') {
            return false;
        }

        $offset = $after;

        return true;
    }

    private static function skipSeparators(string $header, int &$offset, int $length): void
    {
        while ($offset < $length) {
            $char = $header[$offset];
            if ($char !== ',' && !self::isWhitespace($char)) {
                return;
            }

            ++$offset;
        }
    }

    private static function skipWhitespace(string $header, int &$offset, int $length): void
    {
        while ($offset < $length && self::isWhitespace($header[$offset])) {
            ++$offset;
        }
    }

    private static function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\t";
    }

    private static function readToken(string $header, int &$offset, int $length): ?string
    {
        $start = $offset;
        while ($offset < $length && self::isTokenChar($header[$offset])) {
            ++$offset;
        }

        if ($offset === $start) {
            return null;
        }

        $token = \substr($header, $start, $offset - $start);

        return $token === false ? null : $token;
    }

    private static function isTokenChar(string $char): bool
    {
        return \strspn($char, "!#$%&'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz") === 1;
    }

    private static function isToken68Char(string $char): bool
    {
        return \strspn($char, '-._~+/0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') === 1;
    }

    private static function readValue(string $header, int &$offset, int $length): ?string
    {
        if ($offset >= $length) {
            return null;
        }

        if ($header[$offset] === '"') {
            return self::readQuotedString($header, $offset, $length);
        }

        return self::readToken($header, $offset, $length);
    }

    private static function readQuotedString(string $header, int &$offset, int $length): ?string
    {
        ++$offset;
        $value = '';
        $escaped = false;

        while ($offset < $length) {
            $char = $header[$offset++];

            if ($escaped) {
                $value .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                return $value;
            }

            $value .= $char;
        }

        return null;
    }
}
