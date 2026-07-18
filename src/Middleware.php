<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Functions used to create and wrap handlers with handler middleware.
 */
final class Middleware
{
    private function __construct()
    {
    }

    /**
     * Middleware that applies built-in Basic authentication and handles Digest
     * authentication challenges when the "auth" request option is set.
     *
     * @param bool $reuseChallenges Whether Digest challenges may be reused preemptively.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function auth(bool $reuseChallenges = true): callable
    {
        return static function (callable $handler) use ($reuseChallenges): AuthMiddleware {
            return new AuthMiddleware($handler, null, $reuseChallenges);
        };
    }

    /**
     * Middleware that adds cookies to requests.
     *
     * The options array must be set to a CookieJarInterface in order to use
     * cookies. This is typically handled for you by a client.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function cookies(): callable
    {
        return static function (callable $handler): callable {
            return static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                array $options
            ) use ($handler): PromiseInterface {
                if (empty($options['cookies'])) {
                    return $handler($request, $options);
                } elseif (!$options['cookies'] instanceof CookieJarInterface) {
                    throw new \InvalidArgumentException('cookies must be an instance of GuzzleHttp\Cookie\CookieJarInterface');
                }
                $cookieJar = $options['cookies'];
                $request = $cookieJar->withCookieHeader($request);

                return $handler($request, $options)
                    ->then(
                        static function (
                            #[\SensitiveParameter]
                            ResponseInterface $response
                        ) use ($cookieJar, $request): ResponseInterface {
                            $cookieJar->extractCookies($request, $response);

                            return $response;
                        }
                    );
            };
        };
    }

    /**
     * Middleware that throws exceptions for 4xx or 5xx responses when the
     * "http_errors" request option is set to true.
     *
     * @param BodySummarizerInterface|null $bodySummarizer The body summarizer to use in exception messages.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function httpErrors(?BodySummarizerInterface $bodySummarizer = null): callable
    {
        return static function (callable $handler) use ($bodySummarizer): callable {
            return static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                array $options
            ) use ($handler, $bodySummarizer): PromiseInterface {
                if (empty($options['http_errors'])) {
                    return $handler($request, $options);
                }

                return $handler($request, $options)->then(
                    static function (
                        #[\SensitiveParameter]
                        ResponseInterface $response
                    ) use ($request, $bodySummarizer): ResponseInterface {
                        $code = $response->getStatusCode();
                        if ($code < 400) {
                            return $response;
                        }
                        throw RequestException::create($request, $response, null, $bodySummarizer);
                    }
                );
            };
        };
    }

    /**
     * Middleware that pushes history data to an ArrayAccess container.
     *
     * @param array<array-key, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}>|\ArrayAccess<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}> $container Container to hold the history (by reference).
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     *
     * @throws \InvalidArgumentException if container is not an array or ArrayAccess.
     */
    public static function history(&$container): callable
    {
        if (!\is_array($container) && !$container instanceof \ArrayAccess) {
            throw new \InvalidArgumentException('history container must be an array or object implementing ArrayAccess');
        }

        return static function (callable $handler) use (&$container): callable {
            return static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                array $options
            ) use ($handler, &$container): PromiseInterface {
                return $handler($request, $options)->then(
                    static function (
                        #[\SensitiveParameter]
                        ResponseInterface $value
                    ) use ($request, &$container, $options): ResponseInterface {
                        $container[] = [
                            'request' => $request,
                            'response' => $value,
                            'error' => null,
                            'options' => $options,
                        ];

                        return $value;
                    },
                    static function (
                        #[\SensitiveParameter]
                        $reason
                    ) use ($request, &$container, $options): PromiseInterface {
                        $container[] = [
                            'request' => $request,
                            'response' => null,
                            'error' => $reason,
                            'options' => $options,
                        ];

                        return P\Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /**
     * Middleware that observes requests and responses as they flow through the
     * stack without modifying them. This is useful for metrics, tracing, and
     * debugging.
     *
     * The provided listener cannot modify or alter the response. It simply
     * "taps" into the chain to be notified before returning the promise. The
     * before listener accepts a request and options array, and the after
     * listener accepts a request, options array, and response promise.
     *
     * @param (callable(RequestInterface, array<array-key, mixed>): mixed)|null                                             $before Function to invoke before forwarding the request.
     * @param (callable(RequestInterface, array<array-key, mixed>, PromiseInterface<ResponseInterface, mixed>): mixed)|null $after  Function invoked after forwarding.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function tap(?callable $before = null, ?callable $after = null): callable
    {
        return static function (callable $handler) use ($before, $after): callable {
            return static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                array $options
            ) use ($handler, $before, $after): PromiseInterface {
                if ($before) {
                    $before($request, $options);
                }
                $response = $handler($request, $options);
                if ($after) {
                    $after($request, $options, $response);
                }

                return $response;
            };
        };
    }

    /**
     * Middleware that handles request redirects.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function redirect(): callable
    {
        return static function (callable $handler): RedirectMiddleware {
            return new RedirectMiddleware($handler);
        };
    }

    /**
     * Middleware that retries requests based on the boolean result of
     * invoking the provided "decider" function.
     *
     * If no delay function is provided, a simple implementation of exponential
     * backoff will be utilized.
     *
     * @param callable(int, RequestInterface, ResponseInterface|null, mixed): bool $decider Function that accepts the number of retries,
     *                                                                                      a request, [response], and [rejection reason]
     *                                                                                      and returns true if the request is to be retried.
     * @param (callable(int, ResponseInterface|null, RequestInterface): int)|null  $delay   Function that accepts the number of retries,
     *                                                                                      [response], and request, and returns the
     *                                                                                      number of milliseconds to delay.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function retry(callable $decider, ?callable $delay = null): callable
    {
        return static function (callable $handler) use ($decider, $delay): RetryMiddleware {
            return new RetryMiddleware($decider, $handler, $delay);
        };
    }

    /**
     * Middleware that logs requests, responses, and errors using a message
     * formatter.
     *
     * @param LoggerInterface           $logger    Logs messages.
     * @param MessageFormatterInterface $formatter Formatter used to create message strings.
     * @param string                    $logLevel  Level at which to log requests.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function log(LoggerInterface $logger, MessageFormatterInterface $formatter, string $logLevel = 'info'): callable
    {
        return static function (callable $handler) use ($logger, $formatter, $logLevel): callable {
            return static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                array $options = []
            ) use ($handler, $logger, $formatter, $logLevel): PromiseInterface {
                return $handler($request, $options)->then(
                    static function (
                        #[\SensitiveParameter]
                        ResponseInterface $response
                    ) use ($logger, $request, $formatter, $logLevel): ResponseInterface {
                        $message = $formatter->format($request, $response);
                        $logger->log($logLevel, $message);

                        return $response;
                    },
                    /**
                     * @return PromiseInterface<ResponseInterface, mixed>
                     */
                    static function (
                        #[\SensitiveParameter]
                        $reason
                    ) use ($logger, $request, $formatter): PromiseInterface {
                        $response = $reason instanceof ResponseException ? $reason->getResponse() : null;
                        $message = $formatter->format($request, $response, P\Create::exceptionFor($reason));
                        $logger->error($message);

                        return P\Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /**
     * This middleware adds a default content-type if possible, a default
     * content-length or transfer-encoding header, and the expect header.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function prepareBody(): callable
    {
        return static function (callable $handler): PrepareBodyMiddleware {
            return new PrepareBodyMiddleware($handler);
        };
    }

    /**
     * Middleware that applies a map function to the request before passing to
     * the next handler.
     *
     * @param callable(RequestInterface): RequestInterface $fn Function that accepts a RequestInterface and returns
     *                                                         a RequestInterface.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function mapRequest(callable $fn): callable
    {
        return static function (callable $handler) use ($fn): callable {
            return static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                array $options
            ) use ($handler, $fn): PromiseInterface {
                return $handler($fn($request), $options);
            };
        };
    }

    /**
     * Middleware that applies a map function to the resolved promise's
     * response.
     *
     * @param callable(ResponseInterface): ResponseInterface $fn Function that accepts a ResponseInterface and
     *                                                           returns a ResponseInterface.
     *
     * @return callable((callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)): (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)
     */
    public static function mapResponse(callable $fn): callable
    {
        return static function (callable $handler) use ($fn): callable {
            return static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                array $options
            ) use ($handler, $fn): PromiseInterface {
                return $handler($request, $options)->then($fn);
            };
        };
    }
}
