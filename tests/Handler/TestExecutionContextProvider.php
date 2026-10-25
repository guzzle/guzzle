<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Promise\FinalizingExecutionContextProviderInterface;

/**
 * Lets tests switch the current execution context explicitly.
 */
final class TestExecutionContextProvider implements FinalizingExecutionContextProviderInterface
{
    public ?object $current = null;

    /** @var array<int, list<callable(): void>> */
    public array $finalizers = [];

    public function current(): ?object
    {
        return $this->current;
    }

    public function onFinish(callable $finalizer): void
    {
        $this->finalizers[spl_object_id($this->current)][] = $finalizer;
    }

    /**
     * Runs the callback inside the given context and restores the previous one.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function in(?object $context, callable $callback)
    {
        $previous = $this->current;
        $this->current = $context;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    /**
     * Simulates the runtime ending a context by running its finalizers inside it.
     */
    public function end(object $context): void
    {
        $finalizers = $this->finalizers[spl_object_id($context)] ?? [];
        unset($this->finalizers[spl_object_id($context)]);

        $this->in($context, static function () use ($finalizers): void {
            foreach ($finalizers as $finalizer) {
                $finalizer();
            }
        });
    }
}
