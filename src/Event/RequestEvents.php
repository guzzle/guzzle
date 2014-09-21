<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Message\FutureResponse;

/**
 * Contains methods used to manage the request event lifecycle.
 */
final class RequestEvents
{
    // Generic event priorities
    const EARLY = 10000;
    const LATE = -10000;

    // "before" priorities
    const PREPARE_REQUEST = -100;
    const SIGN_REQUEST = -10000;

    // "complete" and "error" response priorities
    const VERIFY_RESPONSE = 100;
    const REDIRECT_RESPONSE = 200;

    /**
     * Converts an array of event options into a formatted array of valid event
     * configuration.
     *
     * @param array $options Event array to convert
     * @param array $events  Event names to convert in the options array.
     * @param mixed $handler Event handler to utilize
     *
     * @return array
     * @throws \InvalidArgumentException if the event config is invalid
     * @internal
     */
    public static function convertEventArray(
        array $options,
        array $events,
        $handler
    ) {
        foreach ($events as $name) {
            if (!isset($options[$name])) {
                $options[$name] = [$handler];
            } elseif (is_callable($options[$name])) {
                $options[$name] = [$options[$name], $handler];
            } elseif (is_array($options[$name])) {
                if (isset($options[$name]['fn'])) {
                    $options[$name] = [$options[$name], $handler];
                } else {
                    $options[$name][] = $handler;
                }
            } else {
                throw new \InvalidArgumentException('Invalid event format');
            }
        }

        return $options;
    }

    /**
     * Stops the DoneEvent from throwing an exception by injecting a future
     * response that throws when dereferenced.
     *
     * @param EndEvent $e
     */
    public static function stopException(EndEvent $e)
    {
        // Keep a reference to the exception because it will be changed.
        $ex = $e->getException();
        // Stop further "end" listeners from firing and add a future response
        // that throws when accessed.
        $e->intercept(new FutureResponse(
            function () use ($ex) { throw $ex; },
            function () { return false; }
        ));
    }
}
