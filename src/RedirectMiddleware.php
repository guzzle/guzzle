<?php
namespace GuzzleHttp;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Request redirect middleware.
 *
 * Apply this middleware like other middleware using
 * {@see GuzzleHttp\Middleware::redirect()}.
 */
class RedirectMiddleware
{
    /** @var callable  */
    private $nextHandler;

    /**
     * @param callable $nextHandler Next handler to invoke.
     */
    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        $fn = $this->nextHandler;
        if (empty($options['allow_redirects'])) {
            return $fn($request, $options);
        }

        $options['allow_redirects'] += [
            'max'       => 5,
            'protocols' => ['http', 'https'],
            'strict'    => false
        ];

        return $fn($request, $options)
            ->then(function (ResponseInterface $response) use ($request, $options) {
                return $this->checkRedirect($request, $options, $response);
            });
    }

    /**
     * @param RequestInterface  $request
     * @param array             $options
     * @param ResponseInterface|PromiseInterface $response
     *
     * @return ResponseInterface|PromiseInterface
     */
    public function checkRedirect(
        RequestInterface $request,
        array $options,
        ResponseInterface $response
    ) {
        if (substr($response->getStatusCode(), 0, 1) != '3'
            || !$response->hasHeader('Location')
        ) {
            return $response;
        }

        $this->guardMax($request, $options);
        $nextRequest = $this->modifyRequest($request, $options, $response);

        return $this($nextRequest, $options);
    }

    private function guardMax(RequestInterface $request, array &$options)
    {
        $current = isset($options['__redirect_count'])
            ? $options['__redirect_count']
            : 0;
        $options['__redirect_count'] = $current + 1;

        if (!isset($options['__redirect_scheme'])) {
            $options['__redirect_scheme'] = $request->getUri()->getScheme();
        }

        $max = $options['allow_redirects']['max'];

        if ($options['__redirect_count'] > $max) {
            throw new TooManyRedirectsException(
                "Will not follow more than {$max} redirects",
                $request
            );
        }
    }

    /**
     * @param RequestInterface  $request
     * @param array             $options
     * @param ResponseInterface $response
     *
     * @return RequestInterface
     */
    public function modifyRequest(
        RequestInterface $request,
        array $options,
        ResponseInterface $response
    ) {
        // Request modifications to apply.
        $modify = [];
        $protocols = $options['allow_redirects']['protocols'];

        // Use a GET request if this is an entity enclosing request and we are
        // not forcing RFC compliance, but rather emulating what all browsers
        // would do.
        $statusCode = $response->getStatusCode();
        if ($statusCode == 303 ||
            ($statusCode <= 302 && $request->getBody() && !$options['allow_redirects']['strict'])
        ) {
            $modify['method'] = 'GET';
            $modify['body'] = '';
        }

        $modify['uri'] = $this->redirectUri($request, $response, $protocols);
        Utils::rewindBody($request);

        // Add the Referer header if it is told to do so and only
        // add the header if we are not redirecting from https to http.
        $scheme = $request->getUri()->getScheme();
        if ($options['allow_redirects']['referer']
            && ($scheme == 'https' || $scheme == $options['__redirect_scheme'])
        ) {
            $uri = $request->getUri()->withUserInfo('', '');
            $modify['set_headers']['Referer'] = (string) $uri;
        } else {
            $modify['remove_headers'][] = 'Referer';
        }

        return Utils::modifyRequest($request, $modify);
    }

    /**
     * Set the appropriate URL on the request based on the location header
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array             $protocols
     *
     * @return UriInterface
     */
    private function redirectUri(
        RequestInterface $request,
        ResponseInterface $response,
        array $protocols
    ) {
        $location = new Uri($response->getHeader('Location'));

        // Combine location with the original URL if it is not absolute.
        if (!$location->getScheme()) {
            // Remove query string parameters and just take what is present on
            // the redirect Location header
            $base = $request->getUri()->withQuery('');
            $location = Uri::resolve($base, $location);
        }

        // Ensure that the redirect URL is allowed based on the protocols.
        if (!in_array($location->getScheme(), $protocols)) {
            throw new BadResponseException(
                sprintf(
                    'Redirect URL, %s, does not use one of the allowed redirect protocols: %s',
                    $location,
                    implode(', ', $protocols)
                ),
                $request,
                $response
            );
        }

        return $location;
    }
}
