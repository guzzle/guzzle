<?php

namespace Guzzle\Http\Event;

use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\Exception\RequestException;

/**
 * Contains a collection of request events and methods used to manage the
 * request event lifecycle (before, after, error).
 */
final class RequestEvents
{
    /**
     * Event emitted before a request is sent
     *
     * The event emitted is a {@see \Guzzle\Http\Event\BeforeEvent} object
     */
    const BEFORE = 'before';

    /**
     * Event emitted when a request has finished sending
     *
     * The event emitted is a {@see \Guzzle\Http\Event\CompleteEvent} object
     */
    const COMPLETE = 'complete';

    /**
     * Event emitted when an error occurs for a given request
     *
     * The event emitted is a {@see \Guzzle\Http\Event\ErrorEvent} object
     */
    const ERROR = 'error';

    /**
     * Event emitted after receiving all of the headers of a non-information response.
     *
     * The event emitted is a {@see \Guzzle\Http\Event\HeadersEvent} object
     */
    const HEADERS = 'headers';

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
                RequestEvents::BEFORE,
                new BeforeEvent($transaction)
            );
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
        $transaction->getResponse()->setEffectiveUrl($transaction->getRequest()->getUrl());
        try {
            $transaction->getRequest()->getEmitter()->emit(
                RequestEvents::COMPLETE,
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
     * @param TransactionInterface $transaction
     * @param \Exception           $e
     * @param array                $stats
     *
     * @throws \Guzzle\Http\Exception\RequestException
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

        // Dispatch an event and allow interception
        if (!$request->getEmitter()->emit(
            RequestEvents::ERROR,
            new ErrorEvent($transaction, $e, $stats)
        )->isPropagationStopped()) {
            throw $e;
        }
    }
}
