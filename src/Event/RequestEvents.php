<?php

namespace GuzzleHttp\Event;

use GuzzleHttp\Adapter\TransactionInterface;
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
     * @param TransactionInterface $transaction
     *
     * @throws RequestException
     */
    public static function emitBefore(TransactionInterface $transaction) {
        $request = $transaction->getRequest();
        try {
            $request->getEmitter()->emit(
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
     * @param TransactionInterface $transaction Transaction to emit for
     * @param array                $stats       Transfer stats
     *
     * @throws RequestException
     */
    public static function emitComplete(
        TransactionInterface $transaction,
        array $stats = []
    ) {
        $request = $transaction->getRequest();
        $transaction->getResponse()->setEffectiveUrl($request->getUrl());
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
     * Emits the headers event for a request.
     *
     * @param TransactionInterface $transaction Transaction to emit for
     */
    public static function emitHeaders(TransactionInterface $transaction)
    {
        $transaction->getRequest()->getEmitter()->emit(
            'headers',
            new HeadersEvent($transaction)
        );
    }

    /**
     * Emits an error event for a request and accounts for the propagation
     * of an error event being stopped to prevent the exception from being
     * thrown.
     *
     * @param TransactionInterface $transaction
     * @param \Exception           $e
     * @param array                $stats
     *
     * @throws \GuzzleHttp\Exception\RequestException
     */
    public static function emitError(
        TransactionInterface $transaction,
        \Exception $e,
        array $stats = []
    ) {
        $request = $transaction->getRequest();

        // Convert non-request exception to a wrapped exception
        if (!($e instanceof RequestException)) {
            $e = new RequestException($e->getMessage(), $request, null, $e);
        }

        // Mark the exception as having been emitted for an error event. This
        // works in tandem with the emitBefore method to prevent the error
        // event from being triggered twice for the same exception.
        $e->emittedError(true);

        // Dispatch an event and allow interception
        if (!$request->getEmitter()->emit(
            'error',
            new ErrorEvent($transaction, $e, $stats)
        )->isPropagationStopped()) {
            throw $e;
        }
    }
}
