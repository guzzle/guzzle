<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Auth\DigestAuth;
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

/**
 * Applies built-in Basic authentication and handles Digest authentication challenges.
 */
final class AuthMiddleware
{
    private const DIGEST_MAX_RETRIES = 2;

    /**
     * @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>
     */
    private $nextHandler;

    /**
     * @var callable(): string
     */
    private $cnonceGenerator;

    /**
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $nextHandler
     * @param (callable(): string)|null                                                                       $cnonceGenerator
     */
    public function __construct(callable $nextHandler, ?callable $cnonceGenerator = null)
    {
        $this->nextHandler = $nextHandler;
        $this->cnonceGenerator = $cnonceGenerator ?? static function (): string {
            return \bin2hex(\random_bytes(16));
        };
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
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
    private static function normalizeAuth(array $auth): ?array
    {
        $type = 'basic';
        if (\array_key_exists(2, $auth) && $auth[2] !== null) {
            if (!\is_string($auth[2])) {
                throw new InvalidArgumentException('auth type must be a string');
            }

            $type = \strtolower($auth[2]);
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
    private function sendBasic(RequestInterface $request, array $options, string $username, string $password): PromiseInterface
    {
        unset($options['auth']);

        return ($this->nextHandler)(
            $request->withHeader('Authorization', 'Basic '.\base64_encode($username.':'.$password)),
            $options
        );
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function sendDigest(RequestInterface $request, array $options, string $username, string $password): PromiseInterface
    {
        $probeOptions = self::withTemporarySink($options);
        unset($probeOptions['auth']);

        return ($this->nextHandler)($request->withoutHeader('Authorization'), $probeOptions)->then(
            function (ResponseInterface $response) use ($request, $options, $probeOptions, $username, $password) {
                return $this->handleDigestResponse($request, $options, $probeOptions, $response, $username, $password);
            },
            static function ($reason) use ($probeOptions) {
                return self::restoreOriginalSinkOnRejection($probeOptions, $reason);
            }
        );
    }

    /**
     * @return ResponseInterface|PromiseInterface<ResponseInterface, mixed>
     */
    private function handleDigestResponse(
        RequestInterface $request,
        array $options,
        array $probeOptions,
        ResponseInterface $response,
        string $username,
        string $password
    ) {
        if ($response->getStatusCode() !== 401) {
            return self::restoreOriginalSink($request, $response, $probeOptions);
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
            Psr7\Message::rewindBody($request);
        } catch (\Exception $e) {
            $response = self::restoreOriginalSink($request, $response, $probeOptions);

            throw new ResponseException(
                'Digest authentication failed because the request body could not be rewound',
                $request,
                $response,
                $e
            );
        }

        $authorization = DigestAuth::authorizationHeader(
            $request,
            $challenge,
            $username,
            $password,
            ($this->cnonceGenerator)()
        );

        if ($authorization === null) {
            return self::restoreOriginalSink($request, $response, $probeOptions);
        }

        $response->getBody()->close();

        $retryOptions = $options;
        $retryOptions['__guzzle_digest_retries'] = $retries + 1;
        $downstreamOptions = $retryOptions;
        $downstreamOptions = self::withTemporarySink($downstreamOptions);
        unset($downstreamOptions['auth']);

        $retryRequest = $request->withHeader('Authorization', $authorization);

        return ($this->nextHandler)($retryRequest, $downstreamOptions)->then(
            function (ResponseInterface $retryResponse) use ($retryRequest, $retryOptions, $downstreamOptions, $username, $password) {
                return $this->handleDigestResponse($retryRequest, $retryOptions, $downstreamOptions, $retryResponse, $username, $password);
            },
            static function ($reason) use ($downstreamOptions) {
                return self::restoreOriginalSinkOnRejection($downstreamOptions, $reason);
            }
        );
    }

    private static function withTemporarySink(array $options): array
    {
        if (!empty($options['stream']) || !isset($options[RequestOptions::SINK])) {
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
        RequestInterface $request,
        ResponseInterface $response,
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
    private static function restoreOriginalSinkOnRejection(array $options, $reason): PromiseInterface
    {
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
