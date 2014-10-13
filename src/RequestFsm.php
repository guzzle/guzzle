<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Exception\StateException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Ring\Future\FutureInterface;

/**
 * Responsible for transitioning requests through lifecycle events.
 */
class RequestFsm
{
    private $handler;
    private $mf;
    private $maxTransitions;

    private $states = [
        // When a mock intercepts the emitted "before" event, then we
        // transition to the "complete" intercept state.
        'before'   => [
            'success'    => 'send',
            'intercept'  => 'complete',
            'error'      => 'error'
        ],
        // The complete and error events are handled using the "then" of
        // the RingPHP request, so we exit the FSM.
        'send' => ['error' => 'error'],
        'complete' => [
            'success'    => 'end',
            'intercept'  => 'before',
            'error'      => 'error'
        ],
        'error' => [
            'success'    => 'complete',
            'intercept'  => 'before',
            'error'      => 'end'
        ],
        'end' => []
    ];

    public function __construct(
        callable $handler,
        MessageFactoryInterface $messageFactory,
        $maxTransitions = 200
    ) {
        $this->mf = $messageFactory;
        $this->maxTransitions = $maxTransitions;
        $this->handler = $handler;
    }

    /**
     * Runs the state machine until a terminal state is entered or the
     * optionally supplied $finalState is entered.
     *
     * @param Transaction $trans      Transaction being transitioned.
     * @param string      $finalState The state to stop on. If unspecified,
     *                                runs until a terminal state is found.
     *
     * @throws \Exception if a terminal state throws an exception.
     */
    public function __invoke(Transaction $trans, $finalState = null)
    {
        $trans->_transitionCount = 1;

        if (!$trans->state) {
            $trans->state = 'before';
        }

        while ($trans->state !== $finalState) {

            if (!isset($this->states[$trans->state])) {
                throw new StateException("Invalid state: {$trans->state}");
            } elseif (++$trans->_transitionCount > $this->maxTransitions) {
                throw new StateException('Too many state transitions were '
                    . 'encountered ({$trans->_transitionCount}). This likely '
                    . 'means that a combination of event listeners are in an '
                    . 'infinite loop.');
            }

            $state = $this->states[$trans->state];

            try {
                /** @var callable $fn */
                $fn = [$this, $trans->state];
                if ($fn($trans)) {
                    // Handles transitioning to the "intercept" state.
                    if (isset($state['intercept'])) {
                        $trans->state = $state['intercept'];
                        continue;
                    }
                    throw new StateException('Invalid intercept state '
                        . 'transition from ' . $trans->state);
                }

                if (isset($state['success'])) {
                    // Transition to the success state
                    $trans->state = $state['success'];
                } else {
                    // Break: this is a terminal state with no transition.
                    break;
                }

            } catch (StateException $e) {
                // State exceptions are thrown no matter what.
                throw $e;
            } catch (\Exception $e) {
                $trans->exception = $e;
                // Terminal error states throw the exception.
                if (!isset($state['error'])) {
                    throw $e;
                }
                // Transition to the error state.
                $trans->state = $state['error'];
            }
        }
    }

    private function before(Transaction $trans)
    {
        $trans->request->getEmitter()->emit('before', new BeforeEvent($trans));

        // When a response is set during the before event (i.e., a mock), then
        // we don't need to send anything. Skip ahead to the complete event
        // by returning to to go to the intercept state.
        return (bool) $trans->response;
    }

    private function send(Transaction $trans)
    {
        $fn = $this->handler;
        $trans->response = FutureResponse::proxy(
            $fn(RingBridge::prepareRingRequest($trans)),
            function ($value) use ($trans) {
                RingBridge::completeRingResponse($trans, $value, $this->mf, $this);
                return $trans->response;
            }
        );
    }

    /**
     * Emits the error event and ensures that the exception is set and is an
     * instance of RequestException. If the error event is not intercepted,
     * then the exception is thrown and we transition to the "end" event. This
     * event also allows requests to be retried, and when retried, transitions
     * to the "before" event. Otherwise, when no retries, and the exception is
     * intercepted, transition to the "complete" event.
     */
    private function error(Transaction $trans)
    {
        // Convert non-request exception to a wrapped exception
        if (!($trans->exception instanceof RequestException)) {
            $trans->exception = RequestException::wrapException(
                $trans->request, $trans->exception
            );
        }

        // Dispatch an event and allow interception
        $event = new ErrorEvent($trans);
        $trans->request->getEmitter()->emit('error', $event);

        if ($trans->exception) {
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
    private function complete(Transaction $trans)
    {
        // Futures will have their own end events emitted when dereferenced.
        if ($trans->response instanceof FutureInterface) {
            return false;
        }

        $trans->response->setEffectiveUrl($trans->request->getUrl());
        $trans->request->getEmitter()->emit('complete', new CompleteEvent($trans));

        // Return true to transition to the 'before' state. False otherwise.
        return $trans->state === 'before';
    }

    /**
     * Emits the "end" event and throws an exception if one is present.
     */
    private function end(Transaction $trans)
    {
        // Futures will have their own end events emitted when dereferenced,
        // but still emit, even for futures, when an exception is present.
        if (!$trans->exception && $trans->response instanceof FutureInterface) {
            return;
        }

        $trans->request->getEmitter()->emit('end', new EndEvent($trans));

        // Throw exceptions in the terminal event if the exception was not
        // handled by an "end" event listener.
        if ($trans->exception) {
            throw $trans->exception;
        }
    }
}
