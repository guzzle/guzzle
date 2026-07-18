<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\DiagnosticValue;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Creates a composed Guzzle handler function by stacking middlewares on top of
 * an HTTP handler function.
 *
 * @template THandler
 *
 * @final
 */
class HandlerStack
{
    use NonSerializableTrait;

    /**
     * @var (callable&THandler)|null
     */
    private $handler;

    /**
     * @var array<int, array{0: callable(callable&THandler): (callable&THandler), 1: string|null}>
     */
    private array $stack = [];

    /**
     * @var (callable&THandler)|null
     */
    private $cached;

    /**
     * Creates a default handler stack that can be used by clients.
     *
     * The returned handler will wrap the provided handler or use the most
     * appropriate default handler for your system. The returned HandlerStack
     * has support for authentication, cookies, redirects, HTTP error
     * exceptions, and preparing a body before sending.
     *
     * The returned handler stack can be passed to a client in the "handler"
     * option.
     *
     * @param (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)|null $handler HTTP handler function to use with the stack. If no
     *                                                                                                                        handler is provided, the best handler for your
     *                                                                                                                        system will be utilized.
     *
     * @return self<callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>>
     */
    public static function create(?callable $handler = null): self
    {
        $stack = new self($handler ?: Utils::chooseHandler());
        $stack->push(Middleware::httpErrors(), 'http_errors');
        $stack->push(Middleware::redirect(), 'allow_redirects');
        $stack->push(Middleware::auth(), 'auth');
        $stack->push(Middleware::cookies(), 'cookies');
        $stack->push(Middleware::prepareBody(), 'prepare_body');

        return $stack;
    }

    /**
     * @param (callable&THandler)|null $handler Underlying handler.
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler;
    }

    /**
     * Invokes the handler stack as a composed handler
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ) {
        $handler = $this->resolve();

        return $handler($request, $options);
    }

    /**
     * Set the HTTP handler that actually returns a promise.
     *
     * @param callable&THandler $handler Accepts a request and array of options and returns a value expected by the stack.
     */
    public function setHandler(callable $handler): void
    {
        $this->handler = $handler;
        $this->cached = null;
    }

    /**
     * Returns true if the builder has a handler.
     */
    public function hasHandler(): bool
    {
        return $this->handler !== null;
    }

    /**
     * Unshift a middleware to the bottom of the stack.
     *
     * @param callable(callable&THandler): (callable&THandler) $middleware Middleware function
     * @param string                                           $name       Name to register for this middleware.
     */
    public function unshift(callable $middleware, ?string $name = null): void
    {
        \array_unshift($this->stack, [$middleware, $name]);
        $this->cached = null;
    }

    /**
     * Push a middleware to the top of the stack.
     *
     * @param callable(callable&THandler): (callable&THandler) $middleware Middleware function
     * @param string                                           $name       Name to register for this middleware.
     */
    public function push(callable $middleware, string $name = ''): void
    {
        $this->stack[] = [$middleware, $name];
        $this->cached = null;
    }

    /**
     * Add a middleware before another middleware by name.
     *
     * @param string                                           $findName   Middleware to find
     * @param callable(callable&THandler): (callable&THandler) $middleware Middleware function
     * @param string                                           $withName   Name to register for this middleware.
     */
    public function before(string $findName, callable $middleware, string $withName = ''): void
    {
        $this->splice($findName, $withName, $middleware, true);
    }

    /**
     * Add a middleware after another middleware by name.
     *
     * @param string                                           $findName   Middleware to find
     * @param callable(callable&THandler): (callable&THandler) $middleware Middleware function
     * @param string                                           $withName   Name to register for this middleware.
     */
    public function after(string $findName, callable $middleware, string $withName = ''): void
    {
        $this->splice($findName, $withName, $middleware, false);
    }

    /**
     * Remove a middleware by instance or name from the stack.
     *
     * @param (callable(callable&THandler): (callable&THandler))|string $remove Middleware to remove by instance or name.
     */
    public function remove($remove): void
    {
        if (!\is_string($remove) && !\is_callable($remove)) {
            // TODO: Move this to the parameter definition in 9.0.
            throw new \TypeError(__METHOD__.'(): Argument #1 ($remove) must be of type callable|string');
        }

        $this->cached = null;

        if (\is_string($remove)) {
            $count = \count($this->stack);
            $this->stack = \array_values(\array_filter(
                $this->stack,
                static function (array $tuple) use ($remove): bool {
                    return $tuple[1] !== $remove;
                }
            ));

            if ($count !== \count($this->stack) || !\is_callable($remove)) {
                return;
            }
        }

        $this->stack = \array_values(\array_filter(
            $this->stack,
            static function (array $tuple) use ($remove): bool {
                return $tuple[0] !== $remove;
            }
        ));
    }

    /**
     * Compose the middleware and handler into a single callable function.
     *
     * @return callable&THandler
     */
    public function resolve(): callable
    {
        if ($this->cached === null) {
            if (($prev = $this->handler) === null) {
                throw new \LogicException('No handler has been specified');
            }

            if (!\is_callable($prev)) {
                throw new \LogicException('Handler must be callable');
            }

            foreach (\array_reverse($this->stack) as $fn) {
                if (!\is_array($fn) || !\array_key_exists(0, $fn) || !\is_callable($fn[0])) {
                    throw new \LogicException('Middleware must be callable');
                }

                $prev = $fn[0]($prev);

                if (!\is_callable($prev)) {
                    throw new \LogicException('Middleware must return a callable');
                }
            }

            $this->cached = $prev;
        }

        return $this->cached;
    }

    public function __unserialize(array $data): void
    {
        $this->handler = null;
        $this->stack = [];
        $this->cached = null;

        throw new \LogicException(static::class.' should never be unserialized');
    }

    private function findByName(string $name): int
    {
        foreach ($this->stack as $k => $v) {
            if ($v[1] === $name) {
                return $k;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Middleware not found: %s', DiagnosticValue::escape($name)));
    }

    /**
     * Splices a function into the middleware list at a specific position.
     *
     * @param callable(callable&THandler): (callable&THandler) $middleware
     */
    private function splice(string $findName, string $withName, callable $middleware, bool $before): void
    {
        $this->cached = null;
        $idx = $this->findByName($findName);
        $tuple = [$middleware, $withName];

        if ($before) {
            if ($idx === 0) {
                \array_unshift($this->stack, $tuple);
            } else {
                $replacement = [$tuple, $this->stack[$idx]];
                \array_splice($this->stack, $idx, 1, $replacement);
            }
        } elseif ($idx === \count($this->stack) - 1) {
            $this->stack[] = $tuple;
        } else {
            $replacement = [$this->stack[$idx], $tuple];
            \array_splice($this->stack, $idx, 1, $replacement);
        }
    }
}
