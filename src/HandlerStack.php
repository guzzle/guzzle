<?php
namespace GuzzleHttp;

/**
 * Creates a composed Guzzle handler function by stacking middlewares on top of
 * an HTTP handler function.
 *
 * Prepended middleware is called before appended middleware. The last function
 * that is invoked by the composed handler is a terminal handler (a function
 * that accepts no next handler).
 */
class HandlerStack
{
    /** @var callable */
    private $handler;

    /** @var array */
    private $stack = [];

    /**
     * @param callable $handler Underlying HTTP handler.
     */
    public function __construct(callable $handler = null)
    {
        $this->handler = $handler;
    }

    /**
     * Dumps a string representation of the stack.
     *
     * @return string
     */
    public function __toString()
    {
        $str = '';
        $i = 0;

        foreach (array_reverse($this->stack) as $tuple) {
            $str .= "{$i}) ";
            if ($tuple[1]) {
                $str .= "Name: {$tuple[1]}, ";
            }
            $str .= "Function: " . $this->debugCallable($tuple[0]) . "\n";
            $i++;
        }

        if ($this->handler) {
            $str .= "{$i}) Handler: " . $this->debugCallable($this->handler) . "\n";
        }

        return $str;
    }

    /**
     * Set the HTTP handler that actually returns a promise.
     *
     * @param callable $handler Accepts a request and array of options and
     *                          returns a Promise.
     */
    public function setHandler(callable $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Returns true if the builder has a handler.
     *
     * @return bool
     */
    public function hasHandler()
    {
        return (bool) $this->handler;
    }

    /**
     * Prepend a middleware to the front of the list.
     *
     * @param callable $middleware Middleware function
     * @param string   $name       Name to register for this middleware.
     */
    public function prepend(callable $middleware, $name = null)
    {
        $this->stack[] = [$middleware, $name];
    }

    /**
     * Append a middleware to the end of the list.
     *
     * @param callable $middleware Middleware function
     * @param string   $name       Name to register for this middleware.
     */
    public function append(callable $middleware, $name = '')
    {
        array_unshift($this->stack, [$middleware, $name]);
    }

    /**
     * Add a middleware before another middleware by name.
     *
     * @param string   $findName   Middleware to find
     * @param callable $middleware Middleware function
     * @param string   $withName   Name to register for this middleware.
     */
    public function before($findName, callable $middleware, $withName = '')
    {
        $this->splice($findName, $withName, $middleware, true);
    }

    /**
     * Add a middleware after another middleware by name.
     *
     * @param string   $findName   Middleware to find
     * @param callable $middleware Middleware function
     * @param string   $withName   Name to register for this middleware.
     */
    public function after($findName, callable $middleware, $withName = '')
    {
        $this->splice($findName, $withName, $middleware, false);
    }

    /**
     * Remove a middleware by instance or name from the stack.
     *
     * @param callable|string $remove Middleware to remove by instance or name.
     */
    public function remove($remove)
    {
        $idx = is_callable($remove) ? 0 : 1;

        $this->stack = array_filter(
            $this->stack,
            function ($s) use ($remove, $idx) {
                return $s[$idx] !== $remove;
            }
        );
    }

    /**
     * Compose the middleware and handler into a single callable function.
     *
     * @return callable
     */
    public function resolve()
    {
        if (!($prev = $this->handler)) {
            throw new \LogicException('No handler has been specified');
        }

        foreach ($this->stack as $fn) {
            $prev = $fn[0]($prev);
        }

        return $prev;
    }

    /**
     * @param $name
     * @return int
     */
    private function findByName($name)
    {
        foreach ($this->stack as $k => $v) {
            if ($v[1] === $name) {
                return $k;
            }
        }

        throw new \InvalidArgumentException("Middleware not found: $name");
    }

    /**
     * Splices a function into the middleware list at a specific position.
     *
     * @param          $findName
     * @param          $withName
     * @param callable $middleware
     * @param          $before
     */
    private function splice($findName, $withName, callable $middleware, $before)
    {
        $idx = $this->findByName($findName);
        $replacement = $before
            ? [$this->stack[$idx], [$middleware, $withName]]
            : [[$middleware, $withName], $this->stack[$idx]];
        array_splice($this->stack, $idx, 1, $replacement);
    }

    /**
     * Provides a debug string for a given callable.
     *
     * @param array|callable $fn Function to write as a string.
     *
     * @return string
     */
    private function debugCallable($fn)
    {
        if (is_string($fn)) {
            return "callable({$fn})";
        } elseif (is_array($fn)) {
            return is_string($fn[0])
                ? "callable({$fn[0]}::{$fn[1]})"
                : "callable(['" . get_class($fn[0]) . "', '{$fn[1]}'])";
        } else {
            return 'callable(' . spl_object_hash($fn) . ')';
        }
    }
}
