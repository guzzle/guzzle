<?php
namespace GuzzleHttp;

/**
 * Creates a composed Guzzle handler function by stacking middlewares on top of
 * a base handler function.
 *
 * The builder represents an ordered list. Prepended middleware is called
 * before appended middleware. The last function that is invoked by the
 * composed handler is a terminal handler (a function that accepts no next
 * handler).
 */
class HandlerBuilder
{
    /** @var callable */
    private $handler;

    /** @var array */
    private $stack = [];

    /**
     * @param callable $handler    Underlying handler.
     * @param array    $middleware Ordered middleware to use with the stack.
     */
    public function __construct(
        callable $handler = null,
        array $middleware = []
    ) {
        $this->handler = $handler;
        $this->stack[-1] = $this->stack[1] = [];
        $this->stack[0] = $middleware;
    }

    public function setHandler(callable $handler)
    {
        $this->handler = $handler;
        return $this;
    }

    public function hasHandler()
    {
        return (bool) $this->handler;
    }

    public function prepend(callable $middleware, $sticky = false)
    {
        array_unshift($this->stack[-1 * (bool) $sticky], $middleware);
        return $this;
    }

    public function append(callable $middleware, $sticky = false)
    {
        $this->stack[(bool) $sticky][] = $middleware;
        return $this;
    }

    public function remove(callable $remove)
    {
        for ($i = -1; $i < 2; $i++) {
            $this->stack[$i] = array_filter(
                $this->stack[$i],
                function ($f) use ($remove) {
                    return $f !== $remove;
                }
            );
        }

        return $this;
    }

    public function resolve()
    {
        if (!($prev = $this->handler)) {
            throw new \LogicException('No handler has been specified');
        }

        foreach ($this->stack as $stack) {
            if ($stack) {
                /** @var callable $fn */
                foreach (array_reverse($stack) as $fn) {
                    $prev = $fn($prev);
                }
            }
        }

        return $prev;
    }
}
