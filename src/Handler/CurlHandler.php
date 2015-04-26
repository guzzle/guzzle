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

    /** @var int Total number of idle handles to keep in cache */
    private $maxHandles;

    /** @var int */
    private $totalHandles = 0;

    /**
     * Accepts an associative array of options:
     *
     * - factory: Optional callable factory used to create cURL handles.
     *   The callable is passed a request hash when invoked, and returns an
     *   array of the curl handle, headers resource, and body resource.
     * - max_handles: Maximum number of idle handles (defaults to 5).
     *
     * @param array $options Array of options to use with the handler
     */
    public function __construct(array $options = [])
    {
        $this->factory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory();
        $this->maxHandles = isset($options['max_handles'])
            ? $options['max_handles']
            : 5;
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        // Ensure headers are by reference. They're updated elsewhere.
        $factory = $this->factory;
        $result = $factory($request, $options, $this->checkoutEasyHandle());
        $h = $result[0];
        $hd =& $result[1];
        $bd = $result[2];

        if (isset($options['delay'])) {
            usleep($options['delay'] * 1000);
        }

        curl_exec($h);
        $response['curl']['error'] = curl_error($h);
        $response['curl']['errno'] = curl_errno($h);
        $this->releaseEasyHandle($h);

        return \GuzzleHttp\Promise\promise_for(
            CurlFactory::createResponse(
                $this, $request, $options, $response, $hd, Psr7\stream_for($bd)
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
        $this->totalHandles++;

        return $handle;
    }

    private function releaseEasyHandle($handle)
    {
        if ($this->totalHandles > $this->maxHandles) {
            curl_close($handle);
            $this->totalHandles--;
        } else {
            curl_reset($handle);
            $this->handles[] = $handle;
        }
    }
}
