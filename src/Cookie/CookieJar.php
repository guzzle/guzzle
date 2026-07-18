<?php

declare(strict_types=1);

namespace GuzzleHttp\Cookie;

use GuzzleHttp\HostIdentity;
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Cookie jar that stores cookies as an array
 */
class CookieJar implements CookieJarInterface
{
    private const MAX_SET_COOKIE_FIELD_LENGTH = 8190;
    private const MAX_SET_COOKIE_FIELDS = 50;
    private const MAX_REQUEST_COOKIES = 150;
    private const MAX_COOKIE_HEADER_LENGTH = 8190;

    /**
     * @var SetCookie[] Loaded cookie data
     */
    private array $cookies = [];

    private bool $strictMode;

    /**
     * @param bool  $strictMode  Set to true to throw exceptions when invalid
     *                           cookies are added to the cookie jar.
     * @param array $cookieArray Array of SetCookie objects or a hash of
     *                           arrays that can be used with the SetCookie
     *                           constructor
     */
    public function __construct(
        bool $strictMode = false,
        #[\SensitiveParameter]
        array $cookieArray = []
    ) {
        $this->strictMode = $strictMode;

        foreach ($cookieArray as $cookie) {
            if (!$cookie instanceof SetCookie) {
                $cookie = new SetCookie($cookie);
            }
            $this->setCookie($cookie);
        }
    }

    /**
     * Create a new Cookie jar from an associative array and domain.
     *
     * @param array  $cookies Cookies to create the jar from
     * @param string $domain  Domain to set the cookies to
     */
    public static function fromArray(
        #[\SensitiveParameter]
        array $cookies,
        string $domain
    ): self {
        $cookieJar = new self();
        foreach ($cookies as $name => $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                throw new \InvalidArgumentException('Cookie value must be scalar or stringable');
            }

            $cookieJar->setCookie(new SetCookie([
                'Domain' => $domain,
                'Name' => (string) $name,
                'Value' => (string) $value,
                'Discard' => true,
            ]));
        }

        return $cookieJar;
    }

    /**
     * Evaluate if this cookie should be persisted to storage
     * that survives between requests.
     *
     * @param SetCookie $cookie              Being evaluated.
     * @param bool      $allowSessionCookies If we should persist session cookies
     */
    public static function shouldPersist(SetCookie $cookie, bool $allowSessionCookies = false): bool
    {
        if ($cookie->getExpires() || $allowSessionCookies) {
            if (!$cookie->getDiscard()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds and returns the cookie based on the name
     *
     * @param string $name cookie name to search for
     *
     * @return SetCookie|null cookie that was found or null if not found
     */
    public function getCookieByName(string $name): ?SetCookie
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return \array_map(static function (SetCookie $cookie): array {
            return $cookie->toArray();
        }, $this->getIterator()->getArrayCopy());
    }

    public function clear(?string $domain = null, ?string $path = null, ?string $name = null): void
    {
        if ($domain === null) {
            $this->cookies = [];

            return;
        } elseif ($path === null) {
            $this->cookies = \array_filter(
                $this->cookies,
                static function (SetCookie $cookie) use ($domain): bool {
                    return $cookie->getDomain() === null || !$cookie->matchesDomain($domain);
                }
            );
        } elseif ($name === null) {
            $this->cookies = \array_filter(
                $this->cookies,
                static function (SetCookie $cookie) use ($path, $domain): bool {
                    return !($cookie->getDomain() !== null
                        && $cookie->matchesPath($path)
                        && $cookie->matchesDomain($domain));
                }
            );
        } else {
            $this->cookies = \array_filter(
                $this->cookies,
                static function (SetCookie $cookie) use ($path, $domain, $name): bool {
                    return !($cookie->getDomain() !== null
                        && $cookie->getName() === $name
                        && $cookie->matchesPath($path)
                        && $cookie->matchesDomain($domain));
                }
            );
        }
    }

    public function clearSessionCookies(): void
    {
        $this->cookies = \array_filter(
            $this->cookies,
            static function (SetCookie $cookie): bool {
                return !$cookie->getDiscard() && $cookie->getExpires();
            }
        );
    }

    public function setCookie(
        #[\SensitiveParameter]
        SetCookie $cookie
    ): bool {
        // If the name string is empty (but not 0), ignore the set-cookie
        // string entirely.
        $name = $cookie->getName();
        if (!$name && $name !== '0') {
            return false;
        }

        // Only allow cookies with set and valid domain, name, value
        $result = $cookie->validate();
        if ($result !== true) {
            if ($this->strictMode) {
                throw new \RuntimeException('Invalid cookie: '.$result);
            }
            $this->removeCookieIfEmpty($cookie);

            return false;
        }

        $maxAge = $cookie->getMaxAge();
        if ($maxAge !== null && $maxAge <= 0) {
            if ($cookie->getDomain() !== null) {
                $this->removeCookie($cookie);
            }

            return false;
        }

        // Resolve conflicts with previously set cookies
        foreach ($this->cookies as $i => $c) {
            // Two cookies are identical, when their path, and domain are
            // identical.
            if ($c->getPath() !== $cookie->getPath()
                || $c->getDomain() !== $cookie->getDomain()
                || $c->getHostOnly() !== $cookie->getHostOnly()
                || $c->getName() !== $cookie->getName()
            ) {
                continue;
            }

            // The previously set cookie is a discard cookie and this one is
            // not so allow the new cookie to be set
            if (!$cookie->getDiscard() && $c->getDiscard()) {
                unset($this->cookies[$i]);
                continue;
            }

            // If the new cookie's expiration is further into the future, then
            // replace the old cookie
            if ($cookie->getExpires() > $c->getExpires()) {
                unset($this->cookies[$i]);
                continue;
            }

            // If the value has changed, we better change it
            if ($cookie->getValue() !== $c->getValue()) {
                unset($this->cookies[$i]);
                continue;
            }

            // The cookie exists, so no need to continue
            return false;
        }

        $this->cookies[] = $cookie;

        return true;
    }

    public function count(): int
    {
        return \count($this->cookies);
    }

    /**
     * @return \ArrayIterator<int, SetCookie>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(\array_values($this->cookies));
    }

    public function extractCookies(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        ResponseInterface $response
    ): void {
        if ($cookieHeader = $response->getHeader('Set-Cookie')) {
            $uri = $request->getUri();
            $requestHost = HostIdentity::canonicalHost($uri->getHost());
            $secure = $uri->getScheme() === 'https';
            $accepted = 0;

            foreach ($cookieHeader as $cookie) {
                if (\strlen($cookie) > self::MAX_SET_COOKIE_FIELD_LENGTH) {
                    continue;
                }

                $sc = SetCookie::fromString($cookie);
                $domain = $sc->getDomain();
                if ($domain === null || $domain === '') {
                    $sc->setDomain($requestHost);
                    $sc->setHostOnly(true);
                } else {
                    $sc->setHostOnly(false);
                }
                if (0 !== \strpos($sc->getPath(), '/')) {
                    $sc->setPath($this->getCookiePathFromRequest($request));
                }
                if (!$sc->matchesDomain($requestHost)) {
                    continue;
                }
                if (!$secure && ($sc->getSecure() || $this->overlaysSecureCookie($sc))) {
                    continue;
                }
                $prefixName = Psr7\Utils::asciiToLower((string) $sc->getName());
                if (\str_starts_with($prefixName, '__secure-') && !$sc->getSecure()) {
                    continue;
                }
                if (\str_starts_with($prefixName, '__host-') && (!$sc->getSecure() || !$sc->getHostOnly() || $sc->getPath() !== '/' || !self::hasPathAttribute($cookie))) {
                    continue;
                }
                // Note: At this point `$sc->getDomain()` being a public suffix should
                // be rejected, but we don't want to pull in the full PSL dependency.
                if ($this->setCookie($sc) && ++$accepted === self::MAX_SET_COOKIE_FIELDS) {
                    break;
                }
            }
        }
    }

    private function overlaysSecureCookie(SetCookie $cookie): bool
    {
        foreach ($this->cookies as $stored) {
            if (self::isSecureCookieOverlay($cookie, $stored)) {
                return true;
            }
        }

        return false;
    }

    private static function isSecureCookieOverlay(SetCookie $cookie, SetCookie $stored): bool
    {
        if ($stored->getName() !== $cookie->getName() || !$stored->getSecure() || $stored->isExpired()) {
            return false;
        }

        $domain = $cookie->getDomain();
        $storedDomain = $stored->getDomain();
        if ($domain === null || $storedDomain === null) {
            return false;
        }

        if (!HostIdentity::cookieDomainMatches($storedDomain, $domain) && !HostIdentity::cookieDomainMatches($domain, $storedDomain)) {
            return false;
        }

        return $stored->matchesPath($cookie->getPath());
    }

    /**
     * Mirrors SetCookie::fromString()'s splitting because parsed cookies cannot
     * distinguish an absent Path attribute from a defaulted path.
     */
    private static function hasPathAttribute(string $header): bool
    {
        $parts = \explode(';', $header);
        \array_shift($parts);

        foreach ($parts as $part) {
            $separator = \strpos($part, '=');
            if ($separator !== false && Psr7\Utils::caselessEquals(\trim(\substr($part, 0, $separator), " \t"), 'Path')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Computes cookie path following RFC 6265 section 5.1.4
     *
     * @see https://datatracker.ietf.org/doc/html/rfc6265#section-5.1.4
     */
    private function getCookiePathFromRequest(RequestInterface $request): string
    {
        $uriPath = $request->getUri()->getPath();
        if ('' === $uriPath) {
            return '/';
        }
        if (0 !== \strpos($uriPath, '/')) {
            return '/';
        }
        if ('/' === $uriPath) {
            return '/';
        }
        $lastSlashPos = \strrpos($uriPath, '/');
        if (0 === $lastSlashPos || false === $lastSlashPos) {
            return '/';
        }

        return \substr($uriPath, 0, $lastSlashPos);
    }

    public function withCookieHeader(
        #[\SensitiveParameter]
        RequestInterface $request
    ): RequestInterface {
        $values = [];
        $headerLength = 8;
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = HostIdentity::canonicalHost($uri->getHost());
        $path = $uri->getPath() ?: '/';

        foreach ($this->cookies as $cookie) {
            if ($cookie->getDomain() !== null
                && $cookie->matchesPath($path)
                && $cookie->matchesDomain($host)
                && !$cookie->isExpired()
                && (!$cookie->getSecure() || $scheme === 'https')
            ) {
                $name = (string) $cookie->getName();
                $value = (string) $cookie->getValue();
                $separatorLength = $values === [] ? 0 : 2;
                $valueLength = \strlen($name) + 1 + \strlen($value);
                if ($headerLength + $separatorLength + $valueLength > self::MAX_COOKIE_HEADER_LENGTH) {
                    break;
                }

                $values[] = $name.'='.$value;
                $headerLength += $separatorLength + $valueLength;
                if (\count($values) === self::MAX_REQUEST_COOKIES) {
                    break;
                }
            }
        }

        return $values
            ? $request->withHeader('Cookie', \implode('; ', $values))
            : $request;
    }

    /**
     * If a cookie already exists and the server asks to set it again with a
     * null value, the cookie must be deleted.
     */
    private function removeCookieIfEmpty(SetCookie $cookie): void
    {
        $cookieValue = $cookie->getValue();
        if (($cookieValue === null || $cookieValue === '') && $cookie->getDomain() !== null) {
            $this->removeCookie($cookie);
        }
    }

    private function removeCookie(SetCookie $cookie): void
    {
        $this->cookies = \array_filter(
            $this->cookies,
            static function (SetCookie $stored) use ($cookie): bool {
                return !($stored->getName() === $cookie->getName()
                    && $stored->getPath() === $cookie->getPath()
                    && $stored->getDomain() === $cookie->getDomain()
                    && $stored->getHostOnly() === $cookie->getHostOnly());
            }
        );
    }
}
