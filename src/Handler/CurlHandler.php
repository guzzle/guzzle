<?php

namespace GuzzleHttp\Handler;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP handler that uses cURL easy handles as a transport layer.
 *
 * When using the CurlHandler, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of the "client" key of the request.
 *
 * @final
 */
class CurlHandler
{
    /**
     * @var CurlFactoryInterface
     */
    private $factory;

    /**
     * @var CurlShareHandleState|null
     */
    private $shareHandleState;

    /**
     * Accepts an associative array of options:
     *
     * - handle_factory: Optional curl factory used to create cURL handles.
     * - share: Optional cURL share-handle configuration.
     *
     * @param array{handle_factory?: ?CurlFactoryInterface, share?: mixed} $options Array of options to use with the handler
     */
    public function __construct(array $options = [])
    {
        CurlShareHandleState::assertNoCustomFactoryConflict($options, 'CurlHandler');

        $this->shareHandleState = CurlShareHandleState::fromOption($options['share'] ?? null);

        $this->factory = $options['handle_factory']
            ?? new CurlFactory(
                3,
                $this->shareHandleState !== null ? $this->shareHandleState->handle : null,
                $this->shareHandleState !== null ? $this->shareHandleState->mode : null
            );
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (isset($options['delay'])) {
            \usleep($options['delay'] * 1000);
        }

        $easy = $this->factory->create($request, $options);
        \curl_exec($easy->handle);
        $easy->errno = \curl_errno($easy->handle);

        return CurlFactory::finish($this, $easy, $this->factory);
    }
}
