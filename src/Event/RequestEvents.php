<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Message\MessageFactoryInterface;
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
        $request = $transaction->request;
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
     * @param Transaction $transaction Transaction to emit for
     * @param array       $stats       Transfer stats
     *
     * @throws RequestException
     */
    public static function emitComplete(
        Transaction $transaction,
        array $stats = []
    ) {
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
        if (!$request->getEmitter()->emit(
            'error',
            new ErrorEvent($transaction, $e, $stats)
        )->isPropagationStopped()) {
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

    /**
     * Convert a Guzzle Transaction object into a Guzzle Ring request array.
     *
     * @param Transaction             $trans          Transaction to convert.
     * @param MessageFactoryInterface $messageFactory Factory used to create
     *                                                response objects.
     *
     * @return array Request hash to send via a ring handler.
     */
    public static function createRingRequest(
        Transaction $trans,
        MessageFactoryInterface $messageFactory
    ) {
        $request = $trans->request;
        $url = $request->getUrl();

        // No need to calculate the query string twice.
        if (!($pos = strpos($url, '?'))) {
            $qs = null;
        } else {
            $qs = substr($url, $pos);
        }

        $r = [
            'scheme'       => $request->getScheme(),
            'http_method'  => $request->getMethod(),
            'url'          => $url,
            'uri'          => $request->getPath(),
            'query_string' => $qs,
            'headers'      => $request->getHeaders(),
            'body'         => $request->getBody(),
            'client'       => $request->getConfig()->toArray(),
            'then'         => function ($response) use ($trans, $messageFactory) {
                self::completeRingResponse($trans, $response, $messageFactory);
            }
        ];

        // Emit progress events if any progress listeners are registered.
        if ($request->getEmitter()->hasListeners('progress')) {
            $emitter = $request->getEmitter();
            $r['client']['progress'] = function ($a, $b, $c, $d) use ($trans, $emitter) {
                $emitter->emit(
                    'progress',
                    new ProgressEvent($trans, $a, $b, $c, $d)
                );
            };
        }

        return $r;
    }

    /**
     * Handles the process of processing a response received from a handler.
     */
    private static function completeRingResponse(
        Transaction $trans,
        array $res,
        MessageFactoryInterface $messageFactory
    ) {
        if (!empty($res['status'])) {
            $options = [];
            if (isset($res['version'])) {
                $options['protocol_version'] = $res['version'];
            }
            if (isset($res['reason'])) {
                $options['reason_phrase'] = $res['reason'];
            }

            $trans->response = $messageFactory->createResponse(
                $res['status'],
                $res['headers'],
                $res['body'],
                $options
            );

            if (isset($res['effective_url'])) {
                $trans->response->setEffectiveUrl($res['effective_url']);
            }
        }

        if (!isset($res['error'])) {
            RequestEvents::emitComplete($trans);
        } else {
            RequestEvents::emitError(
                $trans,
                new RequestException(
                    $res['error']->getMessage(),
                    $trans->request,
                    $trans->response,
                    $res['error']
                ),
                isset($res['transfer_info']) ? $res['transfer_info'] : []
            );
        }
    }
}
