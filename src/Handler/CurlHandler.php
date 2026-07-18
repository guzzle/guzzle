<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\DiagnosticValue;
use GuzzleHttp\TransportSharing;
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
    use NonSerializableTrait;

    private const KNOWN_CONSTRUCTOR_OPTIONS = [
        'handle_factory' => true,
        'transport_sharing' => true,
    ];

    private CurlFactoryInterface $factory;

    private bool $ownsFactory;

    private bool $closed = false;

    private ?CurlShareHandleState $shareHandleState;

    /**
     * Accepts an associative array of options:
     *
     * - handle_factory: Optional curl factory used to create cURL handles.
     * - transport_sharing: Optional transport sharing mode.
     *
     * @param array{handle_factory?: ?CurlFactoryInterface, transport_sharing?: mixed} $options Array of options to use with the handler
     */
    public function __construct(array $options = [])
    {
        foreach ($options as $name => $_) {
            if (!isset(self::KNOWN_CONSTRUCTOR_OPTIONS[$name])) {
                throw new InvalidArgumentException(\sprintf('Invalid CurlHandler constructor option "%s".', DiagnosticValue::escape((string) $name)));
            }
        }

        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict($options, 'CurlHandler');
        $transportSharing = $options['transport_sharing'] ?? null;
        $sharingMode = CurlShareHandleState::normalizeMode($transportSharing, 'transport_sharing');

        if (\array_key_exists('handle_factory', $options) && $options['handle_factory'] !== null) {
            $this->shareHandleState = null;
            $this->factory = $options['handle_factory'];
            $this->ownsFactory = false;

            return;
        }

        $this->shareHandleState = $sharingMode !== TransportSharing::NONE
            ? CurlShareHandleState::fromOption($transportSharing)
            : null;

        $this->factory = $this->shareHandleState !== null
            ? new CurlFactory(3, $this->shareHandleState->mode, $this->shareHandleState)
            : new CurlFactory(3);

        $this->ownsFactory = true;
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
        $this->assertOpen();

        if (isset($options['delay'])) {
            \usleep((int) ($options['delay'] * 1000));
        }

        // A Multiplexing::NONE request option holds unconditionally here:
        // the transfer runs alone during the blocking curl_exec(), and even
        // under persistent transport sharing an in-use connection cannot be
        // joined from another multi handle, so it never shares its
        // connection with a concurrent transfer.
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

    public function __unserialize(array $data): void
    {
        $this->closed = true;

        throw new \LogicException(static::class.' should never be unserialized');
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            // Programmer misuse (reusing a closed handler), not a transfer failure;
            // intentionally a LogicException outside the GuzzleException hierarchy.
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
