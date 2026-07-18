<?php

declare(strict_types=1);

namespace GuzzleHttp\Cookie;

use GuzzleHttp\HostIdentity;
use GuzzleHttp\Psr7;

/**
 * Set-Cookie object
 */
class SetCookie
{
    /**
     * @var array
     */
    private const DEFAULTS = [
        'Name' => null,
        'Value' => null,
        'Domain' => null,
        'Path' => '/',
        'Max-Age' => null,
        'Expires' => null,
        'Secure' => false,
        'Discard' => false,
        'HttpOnly' => false,
    ];

    /**
     * @var array Cookie data
     */
    private array $data;

    /**
     * @var bool Whether this cookie was set without a Domain attribute.
     */
    private bool $hostOnly = false;

    /**
     * Create a new SetCookie object from a string.
     *
     * @param string $cookie Set-Cookie header string
     */
    public static function fromString(
        #[\SensitiveParameter]
        string $cookie
    ): self {
        // Create the default return array
        $data = self::DEFAULTS;
        // Explode the cookie string using a series of semicolons
        $pieces = \array_filter(\array_map(static function (string $piece): string {
            return \trim($piece, " \t");
        }, \explode(';', $cookie)));
        // The name of the cookie (first kvp) must exist and include an equal sign.
        if (!isset($pieces[0]) || \strpos($pieces[0], '=') === false) {
            return new self($data);
        }

        // Add the cookie pieces into the parsed data array
        foreach ($pieces as $part) {
            $cookieParts = \explode('=', $part, 2);
            $key = \trim($cookieParts[0], " \t");
            $value = isset($cookieParts[1])
                ? \trim($cookieParts[1], " \t")
                : true;

            // Only check for non-cookies when cookies have been found
            if (!isset($data['Name'])) {
                $data['Name'] = $key;
                $data['Value'] = $value;
            } else {
                foreach (\array_keys(self::DEFAULTS) as $search) {
                    if (Psr7\Utils::caselessEquals($search, $key)) {
                        if ($search === 'Max-Age') {
                            if (\is_string($value) && \preg_match('/^[+-]?[0-9]+$/D', $value) === 1) {
                                $maxAge = self::parseNumericInteger($value);
                                if ($maxAge !== null) {
                                    $data[$search] = $maxAge;
                                }
                            }
                        } elseif ($search === 'Secure' || $search === 'Discard' || $search === 'HttpOnly') {
                            if ($value) {
                                $data[$search] = true;
                            }
                        } elseif (\is_string($value)) {
                            $data[$search] = $value;
                        }
                        continue 2;
                    }
                }
                if (Psr7\Utils::caselessEquals('HostOnly', $key)) {
                    continue;
                }
                $data[$key] = $value;
            }
        }

        return new self($data);
    }

    /**
     * @param array $data Array of cookie data provided by a Cookie parser
     */
    public function __construct(
        #[\SensitiveParameter]
        array $data = []
    ) {
        $this->data = self::DEFAULTS;
        self::validateFieldTypes($data);

        if (\array_key_exists('HostOnly', $data)) {
            $this->setHostOnly($data['HostOnly']);
            unset($data['HostOnly']);
        }

        if (isset($data['Name'])) {
            $this->setName($data['Name']);
        }

        if (isset($data['Value'])) {
            $this->setValue($data['Value']);
        }

        if (isset($data['Domain'])) {
            $this->setDomain($data['Domain']);
        }

        if (isset($data['Path'])) {
            $this->setPath($data['Path']);
        }

        if (isset($data['Max-Age'])) {
            $this->setMaxAge($data['Max-Age']);
        }

        if (isset($data['Expires'])) {
            $this->setExpires($data['Expires']);
        }

        if (isset($data['Secure'])) {
            $this->setSecure($data['Secure']);
        }

        if (isset($data['Discard'])) {
            $this->setDiscard($data['Discard']);
        }

        if (isset($data['HttpOnly'])) {
            $this->setHttpOnly($data['HttpOnly']);
        }

        // Set the remaining values that don't have extra validation logic
        foreach (array_diff(array_keys($data), array_keys(self::DEFAULTS)) as $key) {
            $this->data[$key] = $data[$key];
        }

        // Extract the Expires value and turn it into a UNIX timestamp if needed
        $maxAge = $this->getMaxAge();
        if ($maxAge !== null) {
            $this->setExpires(self::maxAgeToExpires($maxAge, \time()));
        }
    }

    public function __toString(): string
    {
        $str = $this->data['Name'].'='.($this->data['Value'] ?? '').'; ';
        foreach ($this->data as $k => $v) {
            if ($k === 'Domain' && $this->getHostOnly()) {
                continue;
            }
            if ($k !== 'Name' && $k !== 'Value' && $v !== null && $v !== false) {
                if ($k === 'Expires') {
                    $str .= 'Expires='.\gmdate('D, d M Y H:i:s \G\M\T', $v).'; ';
                } else {
                    $str .= ($v === true ? $k : "{$k}={$v}").'; ';
                }
            }
        }

        return \rtrim($str, '; ');
    }

    public function toArray(): array
    {
        $data = $this->data;
        if ($this->getHostOnly()) {
            $data['HostOnly'] = true;
        }

        return $data;
    }

    /**
     * Get the cookie name.
     */
    public function getName(): ?string
    {
        return $this->data['Name'];
    }

    /**
     * Set the cookie name.
     *
     * @param string $name Cookie name
     */
    public function setName(string $name): void
    {
        $this->data['Name'] = $name;
    }

    /**
     * Get the cookie value.
     */
    public function getValue(): ?string
    {
        return $this->data['Value'];
    }

    /**
     * Set the cookie value.
     *
     * @param string $value Cookie value
     */
    public function setValue(string $value): void
    {
        $this->data['Value'] = $value;
    }

    /**
     * Get the domain.
     */
    public function getDomain(): ?string
    {
        return $this->data['Domain'];
    }

    /**
     * Set the domain of the cookie.
     *
     * @param string|null $domain Domain of the cookie
     */
    public function setDomain(?string $domain): void
    {
        $this->data['Domain'] = null === $domain ? null : self::normalizeDomain($domain);
    }

    /**
     * Get whether this cookie is scoped to the origin host only.
     */
    public function getHostOnly(): bool
    {
        return $this->hostOnly;
    }

    /**
     * Set whether this cookie is scoped to the origin host only.
     *
     * @param bool $hostOnly Set to true for host-only cookies
     */
    public function setHostOnly(bool $hostOnly): void
    {
        $this->hostOnly = $hostOnly;
    }

    /**
     * Get the path.
     */
    public function getPath(): string
    {
        return $this->data['Path'];
    }

    /**
     * Set the path of the cookie.
     *
     * @param string $path Path of the cookie
     */
    public function setPath(string $path): void
    {
        $this->data['Path'] = $path;
    }

    /**
     * Maximum lifetime of the cookie in seconds.
     */
    public function getMaxAge(): ?int
    {
        return null === $this->data['Max-Age'] ? null : (int) $this->data['Max-Age'];
    }

    /**
     * Set the max-age of the cookie.
     *
     * @param int|null $maxAge Max age of the cookie in seconds
     */
    public function setMaxAge(?int $maxAge): void
    {
        $this->data['Max-Age'] = $maxAge;
    }

    /**
     * The UNIX timestamp when the cookie Expires.
     */
    public function getExpires(): ?int
    {
        return $this->data['Expires'];
    }

    /**
     * Set the unix timestamp for which the cookie will expire.
     *
     * @param int|string|null $timestamp Unix timestamp or any English textual datetime description.
     */
    public function setExpires($timestamp): void
    {
        if (!is_int($timestamp) && !is_string($timestamp) && null !== $timestamp) {
            // TODO: Move this to the parameter definition in 9.0.
            throw new \TypeError(__METHOD__.'(): Argument #1 ($timestamp) must be of type int|string|null');
        }

        if ($timestamp === null) {
            $this->data['Expires'] = null;

            return;
        }

        if (\is_string($timestamp)) {
            if (\is_numeric($timestamp)) {
                $this->data['Expires'] = self::parseNumericInteger($timestamp);

                return;
            }
        } elseif (\is_int($timestamp)) {
            $this->data['Expires'] = $timestamp;

            return;
        }

        $expires = \strtotime($timestamp);
        $this->data['Expires'] = $expires === false ? null : $expires;
    }

    /**
     * Get whether or not this is a secure cookie.
     */
    public function getSecure(): bool
    {
        return $this->data['Secure'];
    }

    /**
     * Set whether or not the cookie is secure.
     *
     * @param bool $secure Set to true or false if secure
     */
    public function setSecure(bool $secure): void
    {
        $this->data['Secure'] = $secure;
    }

    /**
     * Get whether or not this is a session cookie.
     */
    public function getDiscard(): bool
    {
        return $this->data['Discard'];
    }

    /**
     * Set whether or not this is a session cookie.
     *
     * @param bool $discard Set to true or false if this is a session cookie
     */
    public function setDiscard(bool $discard): void
    {
        $this->data['Discard'] = $discard;
    }

    /**
     * Get whether or not this is an HTTP only cookie.
     */
    public function getHttpOnly(): bool
    {
        return $this->data['HttpOnly'];
    }

    /**
     * Set whether or not this is an HTTP only cookie.
     *
     * @param bool $httpOnly Set to true or false if this is HTTP only
     */
    public function setHttpOnly(bool $httpOnly): void
    {
        $this->data['HttpOnly'] = $httpOnly;
    }

    /**
     * Check if the cookie matches a path value.
     *
     * A request-path path-matches a given cookie-path if at least one of
     * the following conditions holds:
     *
     * - The cookie-path and the request-path are identical.
     * - The cookie-path is a prefix of the request-path, and the last
     *   character of the cookie-path is %x2F ("/").
     * - The cookie-path is a prefix of the request-path, and the first
     *   character of the request-path that is not included in the cookie-
     *   path is a %x2F ("/") character.
     *
     * @param string $requestPath Path to check against
     */
    public function matchesPath(string $requestPath): bool
    {
        $cookiePath = $this->getPath();

        // Match on exact matches or when path is the default empty "/"
        if ($cookiePath === '/' || $cookiePath === $requestPath) {
            return true;
        }

        // Ensure that the cookie-path is a prefix of the request path.
        if (0 !== \strpos($requestPath, $cookiePath)) {
            return false;
        }

        // Match if the last character of the cookie-path is "/"
        if (\substr($cookiePath, -1, 1) === '/') {
            return true;
        }

        // Match if the first character not included in cookie path is "/"
        return \substr($requestPath, \strlen($cookiePath), 1) === '/';
    }

    /**
     * Check if the cookie matches a domain value.
     *
     * @param string $domain Domain to check against
     */
    public function matchesDomain(string $domain): bool
    {
        $cookieDomain = $this->getDomain();
        if (null === $cookieDomain || $cookieDomain === '') {
            return false;
        }

        if ($this->getHostOnly()) {
            return HostIdentity::canonicalCookieDomain($domain) === HostIdentity::canonicalCookieDomain($cookieDomain);
        }

        return HostIdentity::cookieDomainMatches($domain, $cookieDomain);
    }

    /**
     * Check if the cookie is expired.
     */
    public function isExpired(): bool
    {
        return $this->getExpires() !== null && \time() > $this->getExpires();
    }

    /**
     * Check if the cookie is valid according to RFC 6265.
     *
     * @return string|true Returns true if valid or an error message if invalid
     */
    public function validate()
    {
        $name = $this->getName();
        if ($name === null || $name === '') {
            return 'The cookie name must not be empty';
        }

        // Check if any of the invalid characters are present in the cookie name
        if (\preg_match('/[\x00-\x20\x22\x28-\x29\x2c\x2f\x3a-\x40\x5c\x7b\x7d\x7f]/', $name) !== 0) {
            return 'Cookie name must not contain invalid characters: ASCII '
                .'Control characters (0-31;127), space, tab and the '
                .'following characters: ()<>@,;:\"/?={}';
        }

        // Value must not be null. 0 and empty string are valid. Empty strings
        // are technically against RFC 6265, but known to happen in the wild.
        $value = $this->getValue();
        if ($value === null) {
            return 'The cookie value must not be empty';
        }

        // Domains must not be empty, but may be omitted. "0" is not a valid
        // internet domain, but may be used as server name in a private network.
        $domain = $this->getDomain();
        if ($domain === '' || ($domain !== null && \ltrim(\trim($domain, " \n\r\t\0\x0B"), '.') === '')) {
            return 'The cookie domain must not be empty';
        }

        if ($this->getHostOnly() && $domain === null) {
            return 'Host-only cookies must have a domain';
        }

        return true;
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = Psr7\Utils::asciiToLower($domain);

        // Treat trailing-dot domains as host-only, but keep pure-dot domains invalid.
        if ($domain !== '' && \substr($domain, -1) === '.' && \trim($domain, '.') !== '') {
            return '';
        }

        if ($domain !== '' && $domain !== '.' && $domain[0] === '.') {
            $domain = \substr_replace($domain, '', 0, 1);
        }

        return HostIdentity::canonicalCookieDomain($domain);
    }

    private static function maxAgeToExpires(int $maxAge, int $now): int
    {
        if ($maxAge <= 0) {
            return $now - 1;
        }

        // Clamp absurd Max-Age values so addition cannot promote to float
        if ($maxAge > \PHP_INT_MAX - $now) {
            return \PHP_INT_MAX;
        }

        return $now + $maxAge;
    }

    private static function parseNumericInteger(string $value): ?int
    {
        if (!\is_numeric($value)) {
            return null;
        }

        if (\preg_match('/^[+-]?[0-9]+$/D', $value) === 1) {
            $negative = $value[0] === '-';
            $digits = \ltrim($value, '+-');
            $digits = \ltrim($digits, '0');
            $digits = $digits === '' ? '0' : $digits;
            $limit = $negative ? \substr((string) \PHP_INT_MIN, 1) : (string) \PHP_INT_MAX;

            if (\strlen($digits) > \strlen($limit) || (\strlen($digits) === \strlen($limit) && \strcmp($digits, $limit) > 0)) {
                return null;
            }

            return (int) ($negative ? '-'.$digits : $digits);
        }

        $number = (float) $value;
        if (!\is_finite($number) || $number < \PHP_INT_MIN || $number > \PHP_INT_MAX) {
            return null;
        }

        if (\PHP_INT_SIZE === 8 && ($number <= (float) \PHP_INT_MIN || $number >= (float) \PHP_INT_MAX)) {
            return null;
        }

        return (int) $number;
    }

    /**
     * @param mixed[] $data
     */
    private static function validateFieldTypes(
        #[\SensitiveParameter]
        array $data
    ): void {
        foreach (['Name', 'Value', 'Domain', 'Path'] as $field) {
            if (isset($data[$field]) && !\is_string($data[$field])) {
                throw new \InvalidArgumentException(\sprintf('Cookie field "%s" must be a string', $field));
            }
        }

        if (isset($data['Max-Age']) && !\is_int($data['Max-Age'])) {
            throw new \InvalidArgumentException('Cookie field "Max-Age" must be an integer');
        }

        if (isset($data['Expires']) && !\is_int($data['Expires']) && !\is_string($data['Expires'])) {
            throw new \InvalidArgumentException('Cookie field "Expires" must be an integer or string');
        }

        foreach (['Secure', 'Discard', 'HttpOnly'] as $field) {
            if (isset($data[$field]) && !\is_bool($data[$field])) {
                throw new \InvalidArgumentException(\sprintf('Cookie field "%s" must be a boolean', $field));
            }
        }

        if (\array_key_exists('HostOnly', $data) && !\is_bool($data['HostOnly'])) {
            throw new \InvalidArgumentException('Cookie field "HostOnly" must be a boolean');
        }
    }
}
