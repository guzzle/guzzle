<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Fsm;
use GuzzleHttp\Transaction;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Ring\FutureInterface;

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
        $e->intercept(new FutureResponse(
            function () use ($e) { throw $e->getException(); },
            function () { return false; }
        ));
    }

    /**
     * Create a request state machine used to transition requests
     *
     * @return Fsm
     */
    public static function createFsm()
    {
        return new Fsm('before', [
            'before'   => [
                'success'    => 'send',
                'error'      => 'error',
                'transition' => [__CLASS__, 'beforeTransition']
            ],
            'send' => [
                'success' => 'complete',
                'error'   => 'error'
            ],
            'complete' => [
                'success'    => 'end',
                'error'      => 'error',
                'transition' => [__CLASS__, 'completeTransition']
            ],
            'error' => [
                'success'    => 'complete',
                'error'      => 'end',
                'transition' => [__CLASS__, 'ErrorTransition']
            ],
            'end' => [
                'transition' => [__CLASS__, 'endTransition']
            ]
        ]);
    }

    /** @internal */
    public static function beforeTransition(Transaction $transaction)
    {
        $transaction->request->getEmitter()->emit(
            'before',
            new BeforeEvent($transaction)
        );
    }

    /** @internal */
    public static function errorTransition(Transaction $transaction)
    {
        // Convert non-request exception to a wrapped exception
        if (!($transaction->exception instanceof RequestException)) {
            $transaction->exception = new RequestException(
                $transaction->exception->getMessage(),
                $transaction->request,
                null,
                $transaction->exception
            );
        }

        // Dispatch an event and allow interception
        $event = new ErrorEvent($transaction);
        $transaction->request->getEmitter()->emit('error', $event);

        if (!$event->isPropagationStopped()) {
            throw $transaction->exception;
        }

        $transaction->exception = null;
    }

    /** @internal */
    public static function completeTransition(Transaction $trans)
    {
        $trans->response->setEffectiveUrl($trans->request->getUrl());
        $trans->request->getEmitter()->emit(
            'complete',
            new CompleteEvent($trans)
        );
    }

    /** @internal */
    public static function endTransition(Transaction $trans)
    {
        // Futures will have their own done events emitted when dereferenced.
        if ($trans->response instanceof FutureInterface) {
            return;
        }

        $trans->request->getEmitter()->emit('end', new EndEvent($trans));
    }
}
