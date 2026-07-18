<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Auth\DigestAuth;
use GuzzleHttp\Auth\DigestChallenge;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\LazyOpenStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Applies built-in Basic authentication and handles Digest authentication
 * challenges.
 */
final class AuthMiddleware
{
    use NonSerializableTrait;

    private const DIGEST_MAX_RETRIES = 2;

    private const CHALLENGE_CACHE_LIMIT = 32;

    /**
     * Request option carrying nonce counts already spent during a Digest
     * handshake, so stale retries advance instead of repeating a count.
     */
    private const DIGEST_NONCE_COUNTS_OPTION = '__guzzle_digest_nonce_counts';

    /**
     * Headers that describe the request payload. A probe with an empty body
     * must not carry them.
     *
     * @var list<string>
     */
    private const DIGEST_PROBE_PAYLOAD_HEADERS = [
        'Transfer-Encoding',
        'Expect',
        'Trailer',
        'Content-Range',
        'Content-Encoding',
        'Content-MD5',
        'Digest',
        'Content-Digest',
        'Repr-Digest',
    ];

    /**
     * @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>
     */
    private $nextHandler;

    /**
     * @var callable(): string
     */
    private $cnonceGenerator;

    private bool $reuseChallenges;

    private ?string $credentialHashSecret = null;

    /**
     * @var array<string, array{challenge: DigestChallenge, credentials: string, nextNc: int}>
     */
    private array $digestChallenges = [];

    /**
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $nextHandler
     * @param (callable(): string)|null                                                                       $cnonceGenerator
     */
    public function __construct(callable $nextHandler, ?callable $cnonceGenerator = null, bool $reuseChallenges = true)
    {
        $this->nextHandler = $nextHandler;
        $this->cnonceGenerator = $cnonceGenerator ?? static function (): string {
            return \bin2hex(\random_bytes(16));
        };
        $this->reuseChallenges = $reuseChallenges;
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): PromiseInterface {
        $auth = $options['auth'] ?? null;
        if ($auth === null || $auth === false || $auth === [] || \is_string($auth)) {
            return ($this->nextHandler)($request, $options);
        }

        $normalizedAuth = self::normalizeAuth($auth);
        if ($normalizedAuth === null) {
            return ($this->nextHandler)($request, $options);
        }

        [$username, $password, $type] = $normalizedAuth;

        if ($type === 'basic') {
            return $this->sendBasic($request, $options, $username, $password);
        }

        return $this->sendDigest($request, $options, $username, $password);
    }

    /**
     * @param array<array-key, mixed> $auth
     *
     * @return array{0: string, 1: string, 2: 'basic'|'digest'}|null
     */
    private static function normalizeAuth(
        #[\SensitiveParameter]
        array $auth
    ): ?array {
        $type = 'basic';
        if (\array_key_exists(2, $auth) && $auth[2] !== null) {
            if (!\is_string($auth[2])) {
                throw new InvalidArgumentException('auth type must be a string');
            }

            $type = Psr7\Utils::asciiToLower($auth[2]);
        }

        if (!\in_array($type, ['basic', 'digest'], true)) {
            return null;
        }

        if (!\array_key_exists(0, $auth) || !\array_key_exists(1, $auth)) {
            throw new InvalidArgumentException('auth must contain username and password strings');
        }

        if (!\is_string($auth[0]) || !\is_string($auth[1])) {
            throw new InvalidArgumentException('auth must contain username and password strings');
        }

        return [$auth[0], $auth[1], $type];
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function sendBasic(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        string $username,
        #[\SensitiveParameter]
        string $password
    ): PromiseInterface {
        if (\strpos($username, ':') !== false) {
            throw new InvalidArgumentException('Basic authentication username must not contain a colon');
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $username.$password) !== 0) {
            throw new InvalidArgumentException('Basic authentication credentials must not contain ASCII control characters');
        }

        unset($options['auth']);

        return ($this->nextHandler)(
            $request->withHeader('Authorization', 'Basic '.\base64_encode($username.':'.$password)),
            $options
        );
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function sendDigest(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        string $username,
        #[\SensitiveParameter]
        string $password
    ): PromiseInterface {
        $preemptive = $this->reuseChallenges
            ? $this->preemptiveDigestRequest($request, $username, $password)
            : null;

        if ($preemptive !== null) {
            $preemptiveOptions = self::withTemporarySink($options);
            unset($preemptiveOptions['auth']);

            return ($this->nextHandler)($preemptive, $preemptiveOptions)->then(
                function (
                    #[\SensitiveParameter]
                    ResponseInterface $response
                ) use ($request, $options, $preemptiveOptions, $username, $password) {
                    // Clear on 4xx/5xx other than 401 so non-conformant stale-nonce
                    // errors cannot poison the cache indefinitely.
                    $status = $response->getStatusCode();
                    if ($status >= 400 && $status !== 401) {
                        $this->clearDigestChallenge($request);
                    }

                    return $this->handleDigestResponse($request, $options, $preemptiveOptions, $response, $username, $password, false);
                },
                function (
                    #[\SensitiveParameter]
                    $reason
                ) use ($request, $preemptiveOptions) {
                    return $this->handleDigestRejection($request, $preemptiveOptions, $reason, true);
                }
            );
        }

        $probeOptions = self::withTemporarySink($options);
        unset($probeOptions['auth']);

        [$probeRequest, $bodyWithheld] = self::probeRequest($request, $options);

        if ($bodyWithheld) {
            // The probe has no payload, so prepare_body must not add Expect
            // if a custom empty stream has unknown size.
            $probeOptions[RequestOptions::EXPECT] = false;
        }

        return ($this->nextHandler)($probeRequest, $probeOptions)->then(
            function (
                #[\SensitiveParameter]
                ResponseInterface $response
            ) use ($request, $options, $probeOptions, $username, $password, $bodyWithheld) {
                return $this->handleDigestResponse($request, $options, $probeOptions, $response, $username, $password, $bodyWithheld);
            },
            function (
                #[\SensitiveParameter]
                $reason
            ) use ($request, $probeOptions) {
                return $this->handleDigestRejection($request, $probeOptions, $reason, false);
            }
        );
    }

    private function preemptiveDigestRequest(
        #[\SensitiveParameter]
        RequestInterface $request,
        string $username,
        #[\SensitiveParameter]
        string $password
    ): ?RequestInterface {
        try {
            if ($request->getBody()->getSize() !== 0) {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }

        $key = self::digestCacheKey($request);
        if ($key === null) {
            return null;
        }

        $entry = $this->digestChallenges[$key] ?? null;
        if ($entry === null
            || $entry['credentials'] !== $this->digestCredentialKey($username, $password)
            || !self::challengeCoversRequest($entry['challenge'], $request)
        ) {
            return null;
        }

        $authorization = DigestAuth::authorizationHeader(
            $request,
            $entry['challenge'],
            $username,
            $password,
            ($this->cnonceGenerator)(),
            \sprintf('%08x', $entry['nextNc'])
        );

        if ($authorization === null) {
            return null;
        }

        ++$this->digestChallenges[$key]['nextNc'];

        return $request->withHeader('Authorization', $authorization);
    }

    /**
     * Builds the unauthenticated probe request for a Digest handshake.
     *
     * @return array{0: RequestInterface, 1: bool}
     */
    private static function probeRequest(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): array {
        $probe = $request->withoutHeader('Authorization');

        try {
            $size = $probe->getBody()->getSize();
        } catch (\Exception $e) {
            $size = null;
        }

        if ($size === 0) {
            return [$probe, false];
        }

        $streamFactory = self::requireStreamFactory(
            $options[RequestOptions::STREAM_FACTORY] ?? new HttpFactory()
        );

        $probe = $probe->withBody($streamFactory->createStream(''))
            ->withHeader('Content-Length', '0');

        foreach (self::DIGEST_PROBE_PAYLOAD_HEADERS as $header) {
            $probe = $probe->withoutHeader($header);
        }

        return [$probe, true];
    }

    /**
     * @return ResponseInterface|PromiseInterface<ResponseInterface, mixed>
     */
    private function handleDigestResponse(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        array $probeOptions,
        #[\SensitiveParameter]
        ResponseInterface $response,
        string $username,
        #[\SensitiveParameter]
        string $password,
        bool $bodyWithheld
    ) {
        $status = $response->getStatusCode();

        $previousEntry = null;
        if ($this->reuseChallenges && $status === 401) {
            $key = self::digestCacheKey($request);
            $previousEntry = $key !== null ? ($this->digestChallenges[$key] ?? null) : null;
            $this->clearDigestChallenge($request);
        }

        if ($status !== 401) {
            if ($this->reuseChallenges && $response->hasHeader('Authentication-Info')) {
                $this->clearDigestChallenge($request);
            }

            $response = self::restoreOriginalSink($request, $response, $probeOptions);

            if ($bodyWithheld && $status !== 407 && !self::isRedirectLikeResponse($response)) {
                throw new ResponseException(
                    'Digest authentication failed because the server did not issue a challenge; the request was probed without its body',
                    $request,
                    $response
                );
            }

            return $response;
        }

        $challenge = DigestAuth::selectChallenge($response);
        if ($challenge === null) {
            return self::restoreOriginalSink($request, $response, $probeOptions);
        }

        $retries = $options['__guzzle_digest_retries'] ?? 0;
        if (!\is_int($retries)) {
            $retries = 0;
        }

        if (($retries > 0 && !$challenge->stale) || $retries >= self::DIGEST_MAX_RETRIES) {
            return self::restoreOriginalSink($request, $response, $probeOptions);
        }

        try {
            self::rewindBodyForRetry($request, $bodyWithheld);
        } catch (\Exception $e) {
            $response = self::restoreOriginalSink($request, $response, $probeOptions);

            throw new ResponseException(
                'Digest authentication failed because the request body could not be rewound',
                $request,
                $response,
                $e
            );
        }

        $nonceCounts = self::digestNonceCountsFromOptions($options);

        if ($previousEntry !== null
            && $previousEntry['credentials'] === $this->digestCredentialKey($username, $password)
        ) {
            self::rememberDigestNonceCount($nonceCounts, $previousEntry['challenge'], $previousEntry['nextNc']);
        }

        $nc = $challenge->qop === null ? 1 : ($nonceCounts[self::digestNonceCountKey($challenge)] ?? 1);

        $authorization = DigestAuth::authorizationHeader(
            $request,
            $challenge,
            $username,
            $password,
            ($this->cnonceGenerator)(),
            \sprintf('%08x', $nc)
        );

        if ($authorization === null) {
            return self::restoreOriginalSink($request, $response, $probeOptions);
        }

        $response->getBody()->close();

        self::rememberDigestNonceCount($nonceCounts, $challenge, $nc + 1);

        $retryOptions = $options;
        $retryOptions['__guzzle_digest_retries'] = $retries + 1;
        $retryOptions[self::DIGEST_NONCE_COUNTS_OPTION] = $nonceCounts;
        $downstreamOptions = $retryOptions;
        // The nonce-count map only carries handshake state into the retry
        // recursion; downstream handlers must not observe it.
        unset($downstreamOptions[self::DIGEST_NONCE_COUNTS_OPTION]);
        // The caller's delay applies once, before the first Digest leg, not
        // before each handshake retry.
        unset($downstreamOptions[RequestOptions::DELAY]);
        $downstreamOptions = self::withTemporarySink($downstreamOptions);
        unset($downstreamOptions['auth']);

        $retryRequest = $request->withHeader('Authorization', $authorization);

        return ($this->nextHandler)($retryRequest, $downstreamOptions)->then(
            function (
                #[\SensitiveParameter]
                ResponseInterface $retryResponse
            ) use ($retryRequest, $retryOptions, $downstreamOptions, $username, $password, $challenge, $nc) {
                if ($this->reuseChallenges && self::isCacheableDigestSuccess($retryResponse)) {
                    $this->storeDigestChallenge($retryRequest, $challenge, $username, $password, $nc + 1);
                }

                return $this->handleDigestResponse($retryRequest, $retryOptions, $downstreamOptions, $retryResponse, $username, $password, false);
            },
            function (
                #[\SensitiveParameter]
                $reason
            ) use ($retryRequest, $downstreamOptions) {
                return $this->handleDigestRejection($retryRequest, $downstreamOptions, $reason, false);
            }
        );
    }

    private static function isCacheableDigestSuccess(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 400 && !$response->hasHeader('Authentication-Info');
    }

    private function storeDigestChallenge(
        #[\SensitiveParameter]
        RequestInterface $request,
        DigestChallenge $challenge,
        string $username,
        #[\SensitiveParameter]
        string $password,
        int $nextNc
    ): void {
        $key = self::digestCacheKey($request);
        if ($key === null || $challenge->qop === null) {
            return;
        }

        unset($this->digestChallenges[$key]);

        if (\count($this->digestChallenges) >= self::CHALLENGE_CACHE_LIMIT) {
            \array_shift($this->digestChallenges);
        }

        $this->digestChallenges[$key] = [
            'challenge' => $challenge,
            'credentials' => $this->digestCredentialKey($username, $password),
            'nextNc' => $nextNc,
        ];
    }

    private function clearDigestChallenge(RequestInterface $request): void
    {
        $key = self::digestCacheKey($request);
        if ($key !== null) {
            unset($this->digestChallenges[$key]);
        }
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<string, int>
     */
    private static function digestNonceCountsFromOptions(array $options): array
    {
        $counts = $options[self::DIGEST_NONCE_COUNTS_OPTION] ?? [];
        if (!\is_array($counts)) {
            return [];
        }

        $normalized = [];
        foreach ($counts as $key => $nextNc) {
            if (\is_string($key) && \is_int($nextNc) && $nextNc > 0 && $nextNc < \PHP_INT_MAX) {
                $normalized[$key] = $nextNc;
            }
        }

        return $normalized;
    }

    private static function digestNonceCountKey(DigestChallenge $challenge): string
    {
        return \implode("\0", [
            $challenge->realm,
            $challenge->nonce,
            $challenge->qop ?? '',
            $challenge->algorithm['header'],
        ]);
    }

    /**
     * @param array<string, int> $counts
     */
    private static function rememberDigestNonceCount(array &$counts, DigestChallenge $challenge, int $nextNc): void
    {
        if ($challenge->qop === null) {
            return;
        }

        $key = self::digestNonceCountKey($challenge);
        $counts[$key] = \max($counts[$key] ?? 1, $nextNc);
    }

    /**
     * @param mixed $reason
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function handleDigestRejection(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        $reason,
        bool $preemptive
    ): PromiseInterface {
        if ($this->reuseChallenges && $reason instanceof ResponseException) {
            $response = $reason->getResponse();

            if ($response->getStatusCode() === 401
                || $response->hasHeader('Authentication-Info')
                || ($preemptive && $response->getStatusCode() >= 400)
            ) {
                $this->clearDigestChallenge($request);
            }
        }

        return self::restoreOriginalSinkOnRejection($options, $reason);
    }

    private static function digestCacheKey(RequestInterface $request): ?string
    {
        $uri = $request->getUri();
        $scheme = Psr7\Utils::asciiToLower($uri->getScheme());
        $host = HostIdentity::canonicalHost($uri->getHost());

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.$host.':'.$port.'|'.HostIdentity::canonicalHostHeader($request->getHeaderLine('Host'));
    }

    private function digestCredentialKey(
        string $username,
        #[\SensitiveParameter]
        string $password
    ): string {
        if ($this->credentialHashSecret === null) {
            $this->credentialHashSecret = \random_bytes(32);
        }

        return \hash_hmac('sha256', $username."\0".$password, $this->credentialHashSecret);
    }

    private static function challengeCoversRequest(DigestChallenge $challenge, RequestInterface $request): bool
    {
        if ($challenge->domain === []) {
            return true;
        }

        $target = $request->getRequestTarget();
        foreach ($challenge->domain as $space) {
            $prefix = self::protectionSpacePath($space, $request);
            if ($prefix !== null && \str_starts_with($target, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function protectionSpacePath(string $space, RequestInterface $request): ?string
    {
        if ($space === '') {
            return null;
        }

        if ($space[0] === '/') {
            return $space;
        }

        try {
            $uri = new Psr7\Uri($space);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        if (Psr7\UriComparator::isCrossOrigin($uri, self::effectiveRequestUri($request))) {
            return null;
        }

        $path = $uri->getPath();
        $prefix = $path === '' ? '/' : $path;

        if ($uri->getQuery() !== '') {
            $prefix .= '?'.$uri->getQuery();
        }

        return $prefix;
    }

    private static function effectiveRequestUri(RequestInterface $request): UriInterface
    {
        $uri = $request->getUri();
        $host = $request->getHeaderLine('Host');
        if ($host === '' || Psr7\Utils::caselessEquals($host, $uri->getHost())) {
            return $uri;
        }

        try {
            $authority = new Psr7\Uri('//'.$host);
            if ($authority->getHost() === '') {
                return $uri;
            }

            return $uri->withHost($authority->getHost())->withPort($authority->getPort());
        } catch (\InvalidArgumentException $e) {
            return $uri;
        }
    }

    private static function isRedirectLikeResponse(ResponseInterface $response): bool
    {
        return $response->hasHeader('Location')
            && \in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true);
    }

    private static function rewindBodyForRetry(
        #[\SensitiveParameter]
        RequestInterface $request,
        bool $bodyWithheld
    ): void {
        $body = $request->getBody();

        if (!$bodyWithheld) {
            try {
                if ($body->getSize() === 0) {
                    return;
                }
            } catch (\Exception $e) {
                // Fall through to the standard rewind path.
            }

            Psr7\Message::rewindBody($request);

            return;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }
    }

    private static function withTemporarySink(
        #[\SensitiveParameter]
        array $options
    ): array {
        // The 'stream' option is deliberately not checked here: the cURL
        // handlers do not support streaming and write each leg to the sink,
        // so a configured sink always needs challenge-body protection.
        if (!isset($options[RequestOptions::SINK])) {
            return $options;
        }

        $streamFactory = self::requireStreamFactory(
            $options[RequestOptions::STREAM_FACTORY] ?? new HttpFactory()
        );

        $options['__guzzle_auth_original_sink'] = $options[RequestOptions::SINK];
        $options[RequestOptions::SINK] = $streamFactory->createStreamFromResource(
            Psr7\Utils::tryFopen('php://temp', 'w+')
        );

        return $options;
    }

    private static function restoreOriginalSink(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        ResponseInterface $response,
        #[\SensitiveParameter]
        array $options
    ): ResponseInterface {
        if (!\array_key_exists('__guzzle_auth_original_sink', $options)) {
            return $response;
        }

        try {
            $source = $response->getBody();
            if ($source->isSeekable()) {
                $source->rewind();
            }

            $target = self::streamForOriginalSink($options['__guzzle_auth_original_sink']);

            Psr7\Utils::copyToStream($source, $target);

            if ($target->isSeekable()) {
                $target->rewind();
            }

            return $response->withBody($target);
        } catch (\Exception $e) {
            throw new ResponseException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Failed to write the response body',
                $request,
                $response,
                $e
            );
        }
    }

    /**
     * @param mixed $reason
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function restoreOriginalSinkOnRejection(
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        $reason
    ): PromiseInterface {
        if (!$reason instanceof ResponseException || !\array_key_exists('__guzzle_auth_original_sink', $options)) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($reason);
        }

        try {
            $response = self::restoreOriginalSink($reason->getRequest(), $reason->getResponse(), $options);

            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($reason->withResponse($response));
        } catch (\Throwable $e) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($e);
        }
    }

    /**
     * @param mixed $sink
     */
    private static function streamForOriginalSink($sink): StreamInterface
    {
        if (\is_string($sink)) {
            return new LazyOpenStream($sink, 'w+');
        }

        if (\is_resource($sink)) {
            return self::streamForResourceSink(Psr7\Utils::streamFor($sink));
        }

        if (!$sink instanceof StreamInterface) {
            throw new InvalidArgumentException(\sprintf(
                'sink must be a resource, string, or %s',
                StreamInterface::class
            ));
        }

        return Psr7\Utils::streamFor($sink);
    }

    /**
     * Decorates a caller-owned sink stream so that closing the response body
     * detaches Guzzle's wrapper without closing the original PHP resource.
     */
    private static function streamForResourceSink(StreamInterface $stream): StreamInterface
    {
        return Psr7\FnStream::decorate($stream, [
            'close' => static function () use ($stream): void {
                $stream->detach();
            },
        ]);
    }

    /**
     * @param mixed $factory
     */
    private static function requireStreamFactory($factory): StreamFactoryInterface
    {
        if (!$factory instanceof StreamFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::STREAM_FACTORY,
                StreamFactoryInterface::class
            ));
        }

        return $factory;
    }
}
