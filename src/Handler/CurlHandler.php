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
     * @var \CurlShareHandle|\CurlSharePersistentHandle|resource|null
     *
     * @phpstan-ignore-next-line property.unusedType (resource is used in PHP 7.x)
     */
    private $shareHandle;

    /**
     * @var bool
     */
    private $isPersistentShare = false;

    /**
     * Accepts an associative array of options:
     *
     * - handle_factory: Optional curl factory used to create cURL handles.
     * - share: Array of CURL_LOCK_DATA_* constants to set on a curl_share_init handle.
     * - share_persistent: Array of CURL_LOCK_DATA_* constants to set on a curl_share_init_persistent handle (PHP 8.5+).
     *
     * If share and share_persistent are both set, share_persistent will be used if the function curl_share_init_persistent is available,
     * otherwise share will be used if the function curl_share_init is available.
     *
     * @param array{handle_factory?: ?CurlFactoryInterface, share?: int[], share_persistent?: int[]} $options Array of options to use with the handler
     */
    public function __construct(array $options = [])
    {
        if (isset($options['share_persistent']) && \function_exists('curl_share_init_persistent')) {
            /** @var int[] $sharePersistent */
            $sharePersistent = $options['share_persistent'];
            /** @var \CurlSharePersistentHandle $handle */
            $handle = \curl_share_init_persistent($sharePersistent);
            $this->shareHandle = $handle;
            $this->isPersistentShare = true;
        } elseif (isset($options['share']) && \function_exists('curl_share_init')) {
            /** @var int[] $share */
            $share = $options['share'];
            $this->shareHandle = \curl_share_init();
            foreach ($share as $lock) {
                \curl_share_setopt($this->shareHandle, \CURLSHOPT_SHARE, $lock);
            }
        }

        $this->factory = $options['handle_factory']
            ?? new CurlFactory(3, $this->shareHandle);
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

    public function __destruct()
    {
        // Only close non-persistent share handles
        // Persistent handles are managed by PHP and should not be closed
        // curl_share_close has no effect in PHP version >= 8.0.0
        if ($this->shareHandle !== null && !$this->isPersistentShare && PHP_VERSION_ID < 80000) {
            /** @phpstan-ignore-next-line */
            \curl_share_close($this->shareHandle);
        }
    }
}
