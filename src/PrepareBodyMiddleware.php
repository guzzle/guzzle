<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Handler\RequestFraming;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Prepares requests that contain a body, adding the Content-Length,
 * Content-Type, and Expect headers.
 *
 * @final
 */
class PrepareBodyMiddleware
{
    use NonSerializableTrait;

    /**
     * @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>
     */
    private $nextHandler;

    /**
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $nextHandler Next handler to invoke.
     */
    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
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
        $fn = $this->nextHandler;
        $bodySize = RequestFraming::bodySize($request);

        // Don't do anything if the request has no body.
        if ($bodySize === 0) {
            return $fn($request, $options);
        }

        $modify = [];

        // Add a default content-type if possible.
        if (!$request->hasHeader('Content-Type')) {
            if ($uri = $request->getBody()->getMetadata('uri')) {
                if (is_string($uri) && $type = Psr7\MimeType::fromFilename($uri)) {
                    $modify['set_headers']['Content-Type'] = $type;
                }
            }
        }

        // Add a default content-length or transfer-encoding header.
        if (!$request->hasHeader('Content-Length')
            && !$request->hasHeader('Transfer-Encoding')
        ) {
            if ($bodySize !== null) {
                $modify['set_headers']['Content-Length'] = (string) $bodySize;
            } elseif ($request->getProtocolVersion() === '1.1') {
                $modify['set_headers']['Transfer-Encoding'] = 'chunked';
            }
        }

        // Add the expect header if needed.
        $this->addExpectHeader($request, $options, $modify, $bodySize);

        return $fn(Psr7\Utils::modifyRequest($request, $modify), $options);
    }

    /**
     * Add expect header
     */
    private function addExpectHeader(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        array &$modify,
        ?int $bodySize
    ): void {
        // Determine if the Expect header should be used
        if ($request->hasHeader('Expect')) {
            return;
        }

        $expect = $options['expect'] ?? null;

        // Return if disabled or not using HTTP/1.1.
        if ($expect === false || '1.1' !== $request->getProtocolVersion()) {
            return;
        }

        // The expect header is unconditionally enabled
        if ($expect === true) {
            $modify['set_headers']['Expect'] = '100-Continue';

            return;
        }

        // By default, send the expect header when the payload is > 1mb
        if ($expect === null) {
            $expect = 1048576;
        }

        // Always add if the body cannot be rewound, the size cannot be
        // determined, or the size is greater than the cutoff threshold
        $body = $request->getBody();

        if ($bodySize === null || $bodySize >= (int) $expect || !$body->isSeekable()) {
            $modify['set_headers']['Expect'] = '100-Continue';
        }
    }
}
