<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * Request redirect middleware.
 *
 * Apply this middleware like other middleware using
 * {@see Middleware::redirect()}.
 *
 * @final
 */
class RedirectMiddleware
{
    use NonSerializableTrait;

    public const HISTORY_HEADER = 'X-Guzzle-Redirect-History';

    public const STATUS_HISTORY_HEADER = 'X-Guzzle-Redirect-Status-History';

    /**
     * @var array
     */
    public const DEFAULT_SETTINGS = [
        'max' => 5,
        'protocols' => ['http', 'https'],
        'strict' => false,
        'referer' => false,
        'track_redirects' => false,
    ];

    /**
     * @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>
     */
    private $nextHandler;

    /**
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $nextHandler Next handler to invoke.
     */
    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
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
        $fn = $this->nextHandler;

        if (empty($options['allow_redirects'])) {
            return $fn($request, $options);
        }

        if ($options['allow_redirects'] === true) {
            $options['allow_redirects'] = self::DEFAULT_SETTINGS;
        } elseif (!\is_array($options['allow_redirects'])) {
            throw new InvalidArgumentException('allow_redirects must be true, false, or array');
        } else {
            // Merge the default settings with the provided settings
            $options['allow_redirects'] += self::DEFAULT_SETTINGS;
        }

        if (empty($options['allow_redirects']['max'])) {
            return $fn($request, $options);
        }

        return $fn($request, $options)
            ->then(function (
                #[\SensitiveParameter]
                ResponseInterface $response
            ) use ($request, $options) {
                return $this->checkRedirect($request, $options, $response);
            });
    }

    /**
     * @return ResponseInterface|PromiseInterface<ResponseInterface, mixed>
     */
    public function checkRedirect(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        ResponseInterface $response
    ) {
        if (!self::isRedirectStatusCode($response->getStatusCode())
            || !$response->hasHeader('Location')
        ) {
            return $response;
        }

        $this->guardMax($request, $response, $options);
        $nextRequest = $this->modifyRequest($request, $options, $response);

        // Remove HTTP origin credentials if URI is cross-origin.
        if (Psr7\UriComparator::isCrossOrigin($request->getUri(), $nextRequest->getUri())) {
            unset($options['auth'], $options['__guzzle_digest_retries']);

            if (defined('\CURLOPT_HTTPAUTH') && defined('\CURLOPT_USERPWD')) {
                unset(
                    $options['curl'][\CURLOPT_HTTPAUTH],
                    $options['curl'][\CURLOPT_USERPWD]
                );
            }
        }

        if (isset($options['allow_redirects']['on_redirect'])) {
            ($options['allow_redirects']['on_redirect'])(
                $request,
                $response,
                $nextRequest->getUri()
            );
        }

        // The caller's delay applies once, before the initial request, not
        // before each followed redirect.
        unset($options['delay']);

        $promise = $this($nextRequest, $options);

        // Add headers to be able to track history of redirects.
        if (!empty($options['allow_redirects']['track_redirects'])) {
            return $this->withTracking(
                $promise,
                (string) $nextRequest->getUri(),
                $response->getStatusCode()
            );
        }

        return $promise;
    }

    /**
     * Enable tracking on promise.
     *
     * @param PromiseInterface<ResponseInterface, mixed> $promise
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function withTracking(
        PromiseInterface $promise,
        #[\SensitiveParameter]
        string $uri,
        int $statusCode
    ): PromiseInterface {
        return $promise->then(
            static function (
                #[\SensitiveParameter]
                ResponseInterface $response
            ) use ($uri, $statusCode): ResponseInterface {
                // Note that we are pushing to the front of the list as this
                // would be an earlier response than what is currently present
                // in the history header.
                $historyHeader = $response->getHeader(self::HISTORY_HEADER);
                $statusHeader = $response->getHeader(self::STATUS_HISTORY_HEADER);
                \array_unshift($historyHeader, $uri);
                \array_unshift($statusHeader, (string) $statusCode);

                return $response->withHeader(self::HISTORY_HEADER, $historyHeader)
                                ->withHeader(self::STATUS_HISTORY_HEADER, $statusHeader);
            }
        );
    }

    /**
     * Check for too many redirects.
     *
     * @throws TooManyRedirectsException Too many redirects.
     */
    private function guardMax(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        ResponseInterface $response,
        #[\SensitiveParameter]
        array &$options
    ): void {
        $current = $options['__redirect_count']
            ?? 0;
        $options['__redirect_count'] = $current + 1;
        $max = $options['allow_redirects']['max'];

        if ($options['__redirect_count'] > $max) {
            throw new TooManyRedirectsException("Will not follow more than {$max} redirects", $request, $response);
        }
    }

    public function modifyRequest(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        ResponseInterface $response
    ): RequestInterface {
        $modify = self::getRedirectRequestModifiers($request, $options, $response);
        $protocols = $options['allow_redirects']['protocols'];

        $uriFactory = $options[RequestOptions::URI_FACTORY] ?? new HttpFactory();
        if (!$uriFactory instanceof UriFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::URI_FACTORY,
                UriFactoryInterface::class
            ));
        }

        $uri = self::redirectUri($uriFactory, $request, $response, $protocols);
        $idnOptions = Idn::normalizeConversionOption($options['idn_conversion'] ?? null);
        if ($idnOptions !== null) {
            $uri = Idn::convertUri($uri, $idnOptions);
        }

        $modify['uri'] = $uri;

        // The body only needs to be rewound when the next request reuses it.
        if (!isset($modify['body'])) {
            try {
                Psr7\Message::rewindBody($request);
            } catch (\Exception $e) {
                throw new ResponseException(
                    'Redirect failed because the request body could not be rewound',
                    $request,
                    $response,
                    $e
                );
            }
        }

        $crossOrigin = Psr7\UriComparator::isCrossOrigin($request->getUri(), $modify['uri']);

        // Add a Referer only when the scheme is unchanged, and reduce it to the
        // origin on cross-origin redirects so a secret-bearing path or query is
        // not leaked (strict-origin-when-cross-origin, like modern browsers).
        if ($options['allow_redirects']['referer']
            && $modify['uri']->getScheme() === $request->getUri()->getScheme()
        ) {
            $referer = $request->getUri()->withUserInfo('')->withFragment('');
            if ($crossOrigin) {
                $referer = $referer->withPath('/')->withQuery('');
            }
            $modify['set_headers']['Referer'] = (string) $referer;
        } else {
            $modify['remove_headers'][] = 'Referer';
        }

        // Remove Authorization and Cookie headers if URI is cross-origin.
        if ($crossOrigin) {
            $modify['remove_headers'][] = 'Authorization';
            $modify['remove_headers'][] = 'Cookie';
        }

        return Psr7\Utils::modifyRequest($request, $modify);
    }

    private static function isRedirectStatusCode(int $statusCode): bool
    {
        return \in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    /**
     * @return array{
     *     method?: string,
     *     body?: StreamInterface,
     *     remove_headers?: list<string>
     * }
     */
    private static function getRedirectRequestModifiers(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        ResponseInterface $response
    ): array {
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 303
            && ($statusCode > 302 || $options['allow_redirects']['strict'])
        ) {
            return [];
        }

        $requestMethod = $request->getMethod();

        if ($requestMethod === 'QUERY' && \in_array($statusCode, [301, 302], true)) {
            return [];
        }

        $streamFactory = $options[RequestOptions::STREAM_FACTORY] ?? new HttpFactory();
        if (!$streamFactory instanceof StreamFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::STREAM_FACTORY,
                StreamFactoryInterface::class
            ));
        }

        return [
            'method' => \in_array($requestMethod, ['GET', 'HEAD', 'OPTIONS'], true) ? $requestMethod : 'GET',
            'body' => $streamFactory->createStream(''),
            'remove_headers' => ['Content-Length', 'Transfer-Encoding'],
        ];
    }

    /**
     * Set the appropriate URL on the request based on the location header.
     */
    private static function redirectUri(
        UriFactoryInterface $uriFactory,
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        ResponseInterface $response,
        array $protocols
    ): UriInterface {
        $location = $response->getHeaderLine('Location');

        try {
            $locationUri = $uriFactory->createUri($location);
            $resolvedUri = Psr7\UriResolver::resolve(
                $request->getUri(),
                $locationUri
            );

            if (!$uriFactory instanceof HttpFactory
                && $locationUri->getScheme() === ''
                && $locationUri->getAuthority() === ''
            ) {
                $resolvedUri = $uriFactory->createUri((string) $resolvedUri);
            }
        } catch (\InvalidArgumentException $e) {
            throw new BadResponseException(\sprintf('Redirect URI, %s, is invalid: %s', Psr7\DiagnosticValue::escape($location), $e->getMessage()), $request, $response, $e);
        }

        // Ensure that the redirect URI is allowed based on the protocols.
        if (!\in_array($resolvedUri->getScheme(), $protocols, true)) {
            throw new BadResponseException(\sprintf('Redirect URI, %s, does not use one of the allowed redirect protocols: %s', Psr7\DiagnosticValue::escape((string) $resolvedUri), \implode(', ', $protocols)), $request, $response);
        }

        return $resolvedUri;
    }
}
