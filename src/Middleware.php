<?php
namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Functions used to create and wrap handlers with handler middleware.
 */
final class Middleware
{
    /**
     * Middleware that adds cookies to requests.
     *
     * @param CookieJarInterface $cookieJar Cookie jar to store state.
     *
     * @return callable Returns a function that accepts the next handler.
     */
    public static function cookies(CookieJarInterface $cookieJar)
    {
        return function (callable $handler) use ($cookieJar) {
            return function ($request, array $options) use ($handler, $cookieJar) {
                $request = $cookieJar->withCookieHeader($request);
                return $handler($request, $options)
                    ->then(function ($response) use ($cookieJar, $request) {
                        $cookieJar->extractCookies($request, $response);
                        return $response;
                    }
                );
            };
        };
    }

    /**
     * Middleware that throws exceptions for 4xx or 5xx responses.
     *
     * @return callable Returns a function that accepts the next handler.
     */
    public static function httpError()
    {
        return function (callable $handler) {
            return $fn = function ($request, array $options) use ($handler) {
                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $handler) {
                        $code = $response->getStatusCode();
                        if ($code < 400) {
                            return $response;
                        }
                        throw $code > 499
                            ? new ServerException("Server error: $code", $request, $response)
                            : new ClientException("Client error: $code", $request, $response);
                    }
                );
            };
        };
    }

    /**
     * Middleware that pushes history data to an ArrayAccess container.
     *
     * @param array $container Container to hold the history (by reference).
     *
     * @return callable Returns a function that accepts the next handler.
     */
    public static function history(array &$container)
    {
        return function (callable $handler) use (&$container) {
            return function ($request, array $options) use ($handler, &$container) {
                $response = $handler($request, $options);
                $response->then(function ($value) use ($request, &$container, $options) {
                    $container[] = [
                        'request'  => $request,
                        'response' => $value,
                        'options'  => $options
                    ];
                });
                return $response;
            };
        };
    }

    /**
     * Middleware that invokes a callback before and after sending a request.
     *
     * The provided listener cannot modify or alter the response. It simply
     * "taps" into the chain to be notified before returning the promise. The
     * before listener accepts a request and options array, and the after
     * listener accepts a request, options array, and response promise.
     *
     * @param callable $before Function to invoke before forwarding the request.
     * @param callable $after  Function invoked after forwarding.
     *
     * @return callable Returns a function that accepts the next handler.
     */
    public static function tap(callable $before = null, callable $after = null)
    {
        return function (callable $handler) use ($before, $after) {
            return function ($request, array $options) use ($handler, $before, $after) {
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
     * @return callable Returns a function that accepts the next handler.
     */
    public static function redirect()
    {
        return function (callable $handler) {
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
     * @param callable $decider Function that accepts the number of retries,
     *                          a request, [response], and [exception] and
     *                          returns true if the request is to be retried.
     * @param callable $delay   Function that accepts the number of retries and
     *                          returns the number of milliseconds to delay.
     *
     * @return callable Returns a function that accepts the next handler.
     */
    public static function retry(callable $decider, callable $delay = null)
    {
        /** @var callable $delay */
        $delay = $delay ?: [__CLASS__, 'exponentialBackoffDelay'];
        return function (callable $handler) use ($decider, $delay) {
            return $f = function ($request, array $options) use ($handler, $decider, $delay, &$f) {
                if (!isset($options['retries'])) {
                    $options['retries'] = 0;
                }
                // Then function used for both onFulfilled and onRejected.
                $g = function ($value) use ($handler, $request, $options, $decider, $delay, &$f) {
                    if ($value instanceof \Exception) {
                        $response = null;
                        $error = $value;
                    } else {
                        $response = $value;
                        $error = null;
                    }
                    if (!$decider($options['retries'], $request, $response, $error)) {
                        return $response;
                    }
                    $options['delay'] = $delay(++$options['retries']);
                    return $f($request, $options);
                };
                return $handler($request, $options)->then($g, $g);
            };
        };
    }

    /**
     * Default exponential backoff delay function.
     *
     * @param $retries
     *
     * @return int
     */
    public static function exponentialBackoffDelay($retries)
    {
        return (int) pow(2, $retries - 1);
    }

    /**
     * This middleware adds a default content-type if possible and a default
     * content-length or transfer-encoding header.
     *
     * @return callable
     */
    public static function prepareBody()
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $modify = [];
                // Add a default content-type if possible.
                if (!$request->hasHeader('Content-Type')) {
                    if ($uri = $request->getBody()->getMetadata('uri')) {
                        if ($type = Psr7\mimetype_from_filename($uri)) {
                            $modify['set_headers']['Content-Type'] = $type;
                        }
                    }
                }
                // Add a default content-length or transfer-encoding header.
                static $skip = ['GET' => true, 'HEAD' => true];
                if (!isset($skip[$request->getMethod()])
                    && !$request->hasHeader('Content-Length')
                    && !$request->hasHeader('Transfer-Encoding')
                ) {
                    $size = $request->getBody()->getSize();
                    if ($size !== null) {
                        $modify['set_headers']['Content-Length'] = $size;
                    } else {
                        $modify['set_headers']['Transfer-Encoding'] = 'chunked';
                    }
                }
                return $handler(Psr7\modify_request($request, $modify), $options);
            };
        };
    }
}
