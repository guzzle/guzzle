<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Ring\FutureInterface;
use GuzzleHttp\Transaction;
use GuzzleHttp\Exception\RequestException;

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
     * Emits the before send event for a request and emits an error
     * event if an error is encountered during the before send.
     *
     * @param Transaction $transaction
     *
     * @throws RequestException
     */
    public static function emitBefore(Transaction $transaction) {
        try {
            $transaction->request->getEmitter()->emit(
                'before',
                new BeforeEvent($transaction)
            );
        } catch (RequestException $e) {
            // When a RequestException has been emitted through emitError, the
            // exception is marked as "emitted". This means that the exception
            // had a chance to be rescued but was not. In this case, this method
            // must not emit the error again, but rather throw the exception.
            // This prevents RequestExceptions encountered during the before
            // event from being emitted to listeners twice.
            if ($e->emittedError()) {
                throw $e;
            }
            self::emitError($transaction, $e);
        } catch (\Exception $e) {
            self::emitError($transaction, $e);
        }
    }

    /**
     * Emits the complete event for a request and emits an error
     * event if an error is encountered during the after send.
     *
     * @param Transaction $transaction Transaction to emit for
     * @param array       $stats       Transfer stats
     *
     * @throws RequestException
     */
    public static function emitComplete(
        Transaction $transaction,
        array $stats = []
    ) {
        // Do not emit complete events when a future response is provided.
        // A future response MUST handle it's own complete event when it is
        // dereferenced (realized).
        if ($transaction->response instanceof FutureInterface) {
            return;
        }

        $request = $transaction->request;
        $transaction->response->setEffectiveUrl($request->getUrl());
        try {
            $request->getEmitter()->emit(
                'complete',
                new CompleteEvent($transaction, $stats)
            );
        } catch (RequestException $e) {
            self::emitError($transaction, $e, $stats);
        }
    }

    /**
     * Emits an error event for a request and accounts for the propagation
     * of an error event being stopped to prevent the exception from being
     * thrown.
     *
     * @param Transaction $transaction
     * @param \Exception  $e
     * @param array       $stats
     *
     * @throws \GuzzleHttp\Exception\RequestException
     */
    public static function emitError(
        Transaction $transaction,
        \Exception $e,
        array $stats = []
    ) {
        $request = $transaction->request;

        // Convert non-request exception to a wrapped exception
        if (!($e instanceof RequestException)) {
            $e = new RequestException($e->getMessage(), $request, null, $e);
        }

        // Mark the exception as having been emitted for an error event. This
        // works in tandem with the emitBefore method to prevent the error
        // event from being triggered twice for the same exception.
        $e->emittedError(true);

        // Dispatch an event and allow interception
        $event = new ErrorEvent($transaction, $e, $stats);
        $request->getEmitter()->emit('error', $event);

        if (!$event->isPropagationStopped()) {
            throw $e;
        }
    }

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
}
