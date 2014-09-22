<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\StateException;
use GuzzleHttp\Ring\FutureInterface;

/**
 * Responsible for transitioning requests through lifecycle events.
 */
class RequestFsm extends Fsm
{
    private $sendFn;

    public function __construct(callable $sendFn)
    {
        $this->sendFn = $sendFn;
        parent::__construct('before', [
            // When a mock intercepts the emitted "before" event, then we
            // transition to the "complete" intercept state.
            'before'   => [
                'success'    => 'send',
                'intercept'  => 'complete',
                'error'      => 'error',
                'transition' => [$this, 'beforeTransition']
            ],
            // The complete and error events are handled using the "then" of
            // the Guzzle-Ring request, so we transition to the exit state
            // after the send state.
            'send' => [
                'success'    => 'exit',
                'error'      => 'error',
                'transition' => [$this, 'sendTransition']
            ],
            'complete' => [
                'success'    => 'end',
                // Intercept here is used to retry a request.
                'intercept'  => 'before',
                'error'      => 'error',
                'transition' => [$this, 'completeTransition']
            ],
            'error' => [
                'success'    => 'complete',
                // Intercept here is used to retry a request.
                'intercept'  => 'before',
                'error'      => 'end',
                'transition' => [$this, 'ErrorTransition']
            ],
            'end' => [
                'transition' => [$this, 'endTransition']
            ],
            // The exit state is used to bail from the FSM.
            'exit' => [
                'transition' => [$this, 'exitTransition']
            ]
        ]);
    }

    protected function beforeTransition(Transaction $trans)
    {
        $trans->request->getEmitter()->emit('before', new BeforeEvent($trans));

        // When a response is set during the before event (i.e., a mock), then
        // we don't need to send anything. Skip ahead to the complete event
        // by returning to to go to the intercept state.
        return (bool) $trans->response;
    }

    /**
     * Sends the request using the provided function.
     */
    protected function sendTransition(Transaction $trans)
    {
        $fn = $this->sendFn;
        $fn($trans);
    }

    /**
     * Emits the error event and ensures that the exception is set and is an
     * instance of RequestException. If the error event is not intercepted,
     * then the exception is thrown and we transition to the "end" event. This
     * event also allows requests to be retried, and when retried, transitions
     * to the "before" event. Otherwise, when no retries, and the exception is
     * intercepted, transition to the "complete" event.
     */
    protected function errorTransition(Transaction $trans)
    {
        if (!$trans->exception) {
            throw new StateException('Invalid error state: no exception');
        }

        // Convert non-request exception to a wrapped exception
        if (!($trans->exception instanceof RequestException)) {
            $trans->exception = RequestException::wrapException(
                $trans->request, $trans->exception
            );
        }

        // Dispatch an event and allow interception
        $event = new ErrorEvent($trans);
        $trans->request->getEmitter()->emit('error', $event);

        if (!$event->isPropagationStopped()) {
            throw $trans->exception;
        }

        $trans->exception = null;

        // Return true to transition to the 'before' state. False otherwise.
        return $trans->state === 'before';
    }

    /**
     * Emits a complete event, and if a request is marked for a retry during
     * the complete event, then the "before" state is transitioned to.
     */
    protected function completeTransition(Transaction $trans)
    {
        // Futures will have their own end events emitted when dereferenced.
        if ($trans->response instanceof FutureInterface) {
            return false;
        }

        if (!$trans->response) {
            throw new StateException('Invalid complete state: no response');
        }

        $trans->response->setEffectiveUrl($trans->request->getUrl());
        $trans->request->getEmitter()->emit('complete', new CompleteEvent($trans));

        // Return true to transition to the 'before' state. False otherwise.
        return $trans->state === 'before';
    }

    /**
     * Emits the "end" event and throws an exception if one is present.
     */
    protected function endTransition(Transaction $trans)
    {
        // Futures will have their own end events emitted when dereferenced.
        if ($trans->response instanceof FutureInterface) {
            return;
        }

        $trans->request->getEmitter()->emit('end', new EndEvent($trans));

        // Throw exceptions in the terminal event if the exception was not
        // handled by an "end" event listener.
        if ($trans->exception) {
            throw $trans->exception;
        }
    }

    /**
     * Ensure that a response or exception are present, and if not, throw an
     * exception.
     *
     * Throws an exception if one is present on the transaction.
     */
    protected function exitTransition(Transaction $trans)
    {
        if (!$trans->response && !$trans->exception) {
            $trans->exception = RingBridge::getNoRingResponseException($trans->request);
        }

        // Only throw if the response is not a future.
        if ($trans->exception && !($trans->response instanceof FutureInterface)) {
            throw $trans->exception;
        }
    }
}
