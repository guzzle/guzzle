<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP handler that uses cURL easy handles as a transport layer.
 *
 * When using the CurlHandler, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of the "client" key of the request.
 */
final class CurlHandler
{
    private CurlFactoryInterface $factory;

    private bool $ownsFactory;

    private bool $closed = false;

    private ?CurlShareHandleState $shareHandleState;

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

        if (\array_key_exists('handle_factory', $options) && $options['handle_factory'] !== null) {
            $this->factory = $options['handle_factory'];
            $this->ownsFactory = false;
        } elseif ($this->shareHandleState !== null) {
            $this->factory = new CurlFactory(3, $this->shareHandleState->mode, $this->shareHandleState->handle);
            $this->ownsFactory = true;
        } else {
            $this->factory = new CurlFactory(3);
            $this->ownsFactory = true;
        }
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->assertOpen();

        if (isset($options['delay'])) {
            \usleep((int) ($options['delay'] * 1000));
        }

        $easy = $this->factory->create($request, $options);
        \curl_exec($easy->handle);
        $easy->errno = \curl_errno($easy->handle);

        return CurlFactory::finish($this, $easy, $this->factory);
    }

    /**
     * Closes native cURL resources owned by this handler.
     *
     * After closing, the handler is terminal and must not be reused.
     */
    public function close(): void
    {
        $this->doClose(true);
    }

    public function __destruct()
    {
        try {
            $this->doClose(false);
        } catch (\Throwable $e) {
            // Destructors must not throw.
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \BadMethodCallException('Cannot use the cURL handler after it has been closed.');
        }
    }

    private function doClose(bool $explicit): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            if ($this->ownsFactory && $this->factory instanceof CurlFactory) {
                $this->factory->close();
            }
        } catch (\Throwable $e) {
            if ($explicit) {
                throw $e;
            }
        } finally {
            $this->shareHandleState = null;
        }
    }
}
