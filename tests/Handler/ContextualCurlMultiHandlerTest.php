<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\ContextualCurlMultiHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\ExecutionContext;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransportSharing;
use PHPUnit\Framework\TestCase;

class ContextualCurlMultiHandlerTest extends TestCase
{
    private TestExecutionContextProvider $provider;

    /** @var list<CurlMultiHandler> */
    private array $created = [];

    protected function setUp(): void
    {
        if (\PHP_VERSION_ID < 80000) {
            self::markTestSkipped('Execution context providers require PHP 8.0 or newer.');
        }

        $this->provider = new TestExecutionContextProvider();
        ExecutionContext::setProvider($this->provider);
        Server::flush();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setProvider(null);
        P\Utils::queue()->run();
    }

    private function handler(int $maxIdleHandlers = 8): ContextualCurlMultiHandler
    {
        return new ContextualCurlMultiHandler(function (): CurlMultiHandler {
            return $this->created[] = new CurlMultiHandler();
        }, $maxIdleHandlers);
    }

    public function testRejectsNegativePoolSizes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContextualCurlMultiHandler(static fn (): CurlMultiHandler => new CurlMultiHandler(), -1);
    }

    public function testLeasesOneHandlerPerContext(): void
    {
        Server::enqueue([new Response(200), new Response(200), new Response(200)]);
        $handler = $this->handler();
        $a = new \stdClass();
        $b = new \stdClass();

        $this->provider->in($a, static function () use ($handler): void {
            $handler(new Request('GET', Server::$url), [])->wait();
            $handler(new Request('GET', Server::$url), [])->wait();
        });
        $this->provider->in($b, static fn () => $handler(new Request('GET', Server::$url), [])->wait());

        self::assertCount(2, $this->created);
    }

    public function testRequestsOutsideManagedContextsShareOneHandler(): void
    {
        Server::enqueue([new Response(200), new Response(200)]);
        $handler = $this->handler();

        $handler(new Request('GET', Server::$url), [])->wait();
        $handler(new Request('GET', Server::$url), [])->wait();

        self::assertCount(1, $this->created);
    }

    public function testFinishingAContextCancelsItsTransfersInsideThatContext(): void
    {
        Server::enqueue([new Response(200)]);
        $handler = $this->handler();
        $context = new \stdClass();
        $rejectedIn = null;

        $promise = $this->provider->in($context, function () use ($handler, &$rejectedIn) {
            return $handler(new Request('GET', Server::$url), [])->otherwise(function ($reason) use (&$rejectedIn) {
                $rejectedIn = $this->provider->current;

                return $reason;
            });
        });

        $this->provider->end($context);

        self::assertSame($context, $rejectedIn);
        self::assertInstanceOf(CancellationException::class, $promise->wait());
    }

    public function testIdleHandlersReturnToThePoolForLaterContexts(): void
    {
        Server::enqueue([new Response(200), new Response(200)]);
        $handler = $this->handler();
        $first = new \stdClass();

        $this->provider->in($first, static fn () => $handler(new Request('GET', Server::$url), [])->wait());
        $this->provider->end($first);
        $this->provider->in(new \stdClass(), static fn () => $handler(new Request('GET', Server::$url), [])->wait());

        self::assertCount(1, $this->created);
    }

    public function testPooledHandlersIgnoreTicksQueuedByTheirPreviousContext(): void
    {
        Server::enqueue([new Response(200), new Response(201)]);
        $handler = $this->handler();
        $first = new \stdClass();
        $second = new \stdClass();
        $progressIn = [];
        $promise = null;

        $this->provider->in($first, function () use ($handler, $second, &$progressIn, &$promise): void {
            $handler(new Request('GET', Server::$url), [])->wait();

            // Leave a tick queued in this context, then hand the pooled handler
            // to another context before this context's queue is drained.
            P\Utils::queue()->add(static function (): void {
                throw new \RuntimeException('queued task failure');
            });

            try {
                $this->created[0]->tick();
            } catch (\RuntimeException $e) {
                // The tick unwound with its own queued tick still pending.
            }

            ExecutionContext::onFinish(function () use ($handler, $second, &$progressIn, &$promise): void {
                $promise = $this->provider->in($second, function () use ($handler, &$progressIn) {
                    return $handler(new Request('GET', Server::$url), ['progress' => function () use (&$progressIn): void {
                        $progressIn[] = $this->provider->current;
                    }]);
                });
            });
        });

        $this->provider->end($first);
        $response = $this->provider->in($second, static fn () => $promise->wait());

        self::assertCount(1, $this->created);
        self::assertSame(201, $response->getStatusCode());
        self::assertNotContains($first, $progressIn, 'A stale tick drove the next context\'s transfer.');
    }

    public function testHandlersAreNotPooledWhenThePoolIsDisabled(): void
    {
        Server::enqueue([new Response(200), new Response(200)]);
        $handler = $this->handler(0);
        $first = new \stdClass();

        $this->provider->in($first, static fn () => $handler(new Request('GET', Server::$url), [])->wait());
        $this->provider->end($first);
        $this->provider->in(new \stdClass(), static fn () => $handler(new Request('GET', Server::$url), [])->wait());

        self::assertCount(2, $this->created);
    }

    public function testLeasesDoNotKeepContextsAlive(): void
    {
        Server::enqueue([new Response(200)]);
        $handler = $this->handler();
        $context = new \stdClass();
        $reference = \WeakReference::create($context);

        $this->provider->in($context, static fn () => $handler(new Request('GET', Server::$url), [])->wait());
        $this->provider->finalizers = [];
        unset($context);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    public function testDefaultClientsIsolateAsyncTransfersPerContext(): void
    {
        Server::enqueue([new Response(200), new Response(201)]);
        $client = new Client();
        $a = new \stdClass();
        $b = new \stdClass();

        $pending = $this->provider->in($a, static fn () => $client->getAsync(Server::$url));
        $response = $this->provider->in($b, static fn () => $client->getAsync(Server::$url)->wait());

        self::assertTrue(P\Is::pending($pending), 'Another context drove the transfer.');
        self::assertContains($response->getStatusCode(), [200, 201]);
        $this->provider->in($a, static fn () => $pending->wait());
    }

    public function testDefaultClientCanBeSharedByInterleavedFibers(): void
    {
        if (\PHP_VERSION_ID < 80100) {
            self::markTestSkipped('Fibers require PHP 8.1 or newer.');
        }

        ExecutionContext::setProvider(new P\FiberExecutionContextProvider());
        Server::enqueue([new Response(200), new Response(200)]);
        $client = new Client();
        $request = static function () use ($client): int {
            try {
                $promise = $client->getAsync(Server::$url);
                \Fiber::suspend($promise);

                return $promise->wait()->getStatusCode();
            } finally {
                ExecutionContext::finish(\Fiber::getCurrent());
            }
        };
        $first = new \Fiber($request);
        $second = new \Fiber($request);
        $pending = $first->start();
        $second->start();

        $second->resume();
        self::assertTrue(P\Is::pending($pending), 'Another fiber drove the transfer.');
        $first->resume();

        self::assertSame(200, $first->getReturn());
        self::assertSame(200, $second->getReturn());
    }

    /**
     * @testWith [0, 2]
     *           [1, 1]
     *           [2, 0]
     */
    public function testClientPoolSizeControlsConnectionReuse(int $maxIdleHandlers, int $newConnections): void
    {
        Server::enqueue([new Response(200), new Response(200), new Response(200), new Response(200)]);
        $client = new Client(['max_idle_handlers' => $maxIdleHandlers]);
        $ports = [];

        for ($wave = 0; $wave < 2; ++$wave) {
            $contexts = [new \stdClass(), new \stdClass()];
            $promises = [];

            foreach ($contexts as $context) {
                $promises[] = $this->provider->in($context, static function () use ($client, &$ports, $wave) {
                    return $client->getAsync(Server::$url, ['on_stats' => static function (\GuzzleHttp\TransferStats $stats) use (&$ports, $wave): void {
                        $ports[$wave][] = $stats->getHandlerStat('local_port');
                    }]);
                });
            }

            foreach ($contexts as $index => $context) {
                $this->provider->in($context, static fn () => $promises[$index]->wait());
                $this->provider->end($context);
            }
        }

        self::assertCount($newConnections, \array_diff($ports[1], $ports[0]));
        self::assertNull($client->getConfig('max_idle_handlers'));
    }

    public function testLeasedHandlersShareTheTransportStateOfTheSynchronousHandler(): void
    {
        if (!CurlVersion::supportsCurlHandler() || !CurlVersion::supportsHandlerSharing()) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }

        Server::enqueue([new Response(200), new Response(200), new Response(200)]);
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);

        try {
            $client = new Client(['transport_sharing' => TransportSharing::HANDLER_PREFER]);
            $this->provider->in(new \stdClass(), static fn () => $client->getAsync(Server::$url)->wait());
            $this->provider->in(new \stdClass(), static fn () => $client->getAsync(Server::$url)->wait());
            $client->get(Server::$url);

            self::assertSame(1, $_SERVER['_curl_share_init_count']);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testClientsWithConnectionCapsKeepOneSharedHandler(): void
    {
        Server::enqueue([new Response(200)]);
        $client = new Client(['max_total_connections' => 1]);
        $a = new \stdClass();
        $b = new \stdClass();

        $pending = $this->provider->in($a, static fn () => $client->getAsync(Server::$url));

        // The client turns handler exceptions into rejected promises.
        $rejected = $this->provider->in($b, static fn () => $client->getAsync(Server::$url));
        $reason = $this->provider->in($b, static fn () => $rejected->wait(false) ?? P\Utils::inspect($rejected)['reason']);

        self::assertInstanceOf(P\CrossContextException::class, $reason);
        self::assertSame(200, $this->provider->in($a, static fn () => $pending->wait())->getStatusCode());
    }
}
