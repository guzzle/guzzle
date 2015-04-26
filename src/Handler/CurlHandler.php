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
    /** @var callable */
    private $factory;

    /** @var array */
    private $handles;

    /**
     * Accepts an associative array of options:
     *
     * - factory: Optional callable factory used to create cURL handles.
     *   The callable is passed a request hash when invoked, and returns an
     *   array of the curl handle, headers resource, and body resource.
     *
     * @param array $options Array of options to use with the handler
     */
    public function __construct(array $options = [])
    {
        $this->factory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory();
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        // Ensure headers are by reference. They're updated elsewhere.
        $factory = $this->factory;
        $easy = $factory($request, $options, $this->checkoutEasyHandle());

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
        $this->releaseEasyHandle($easy->handle);

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

    private function checkoutEasyHandle()
    {
        // Find a free handle.
        if ($this->handles) {
            return array_pop($this->handles);
        }

        // Add a new handle
        $handle = curl_init();

        return $handle;
    }

    private function releaseEasyHandle($handle)
    {
        if (count($this->handles) > 3) {
            curl_close($handle);
        } else {
            curl_reset($handle);
            $this->handles[] = $handle;
        }
    }
}
