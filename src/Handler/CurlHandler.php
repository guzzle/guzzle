<?php
namespace GuzzleHttp\Handler;

use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP handler that uses cURL easy handles as a transport layer.
 *
 * When using the CurlHandler, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of the "client" key of the request.
 */
class CurlHandler
{
    /** @var CurlFactoryInterface */
    private $factory;

    /**
     * Accepts an associative array of options:
     *
     * - factory: Optional curl factory used to create cURL handles.
     *
     * @param array $options Array of options to use with the handler
     */
    public function __construct(array $options = [])
    {
        $this->factory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory(3);
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        $easy = $this->factory->create($request, $options);

        if (isset($options['delay'])) {
            usleep($options['delay'] * 1000);
        }

        curl_exec($easy->handle);
        $response = [
            'curl' => [
                'error' => curl_error($easy->handle),
                'errno' => curl_errno($easy->handle)
            ]
        ];
        $this->factory->release($easy);

        return \GuzzleHttp\Promise\promise_for(
            CurlFactory::createResponse(
                $this,
                $request,
                $options,
                $response,
                $easy->headers,
                Psr7\stream_for($easy->body)
            )
        );
    }
}
