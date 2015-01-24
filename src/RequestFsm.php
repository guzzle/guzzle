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
     *
     * @throws \Exception if a terminal state throws an exception.
     */
    public function __invoke(Transaction $trans)
    {
        $trans->_transitionCount = 0;

        if (!$trans->state) {
            $trans->state = 'before';
        }

        transition:

        if (++$trans->_transitionCount > $this->maxTransitions) {
            throw new StateException("Too many state transitions were "
                . "encountered ({$trans->_transitionCount}). This likely "
                . "means that a combination of event listeners are in an "
                . "infinite loop.");
        }

        switch ($trans->state) {
            case 'before': goto before;
            case 'complete': goto complete;
            case 'error': goto error;
            case 'retry': goto retry;
            case 'send': goto send;
            case 'end': goto end;
            default: throw new StateException("Invalid state: {$trans->state}");
        }

        before: {
            try {
                $trans->request->getEmitter()->emit('before', new BeforeEvent($trans));
                $trans->state = 'send';
                if ((bool) $trans->response) {
                    $trans->state = 'complete';
                }
            } catch (\Exception $e) {
                $trans->state = 'error';
                $trans->exception = $e;
            }
            goto transition;
        }

        complete: {
            try {
                if ($trans->response instanceof FutureInterface) {
                    // Futures will have their own end events emitted when
                    // dereferenced.
                    return;
                }
                $trans->state = 'end';
                $trans->response->setEffectiveUrl($trans->request->getUrl());
                $trans->request->getEmitter()->emit('complete', new CompleteEvent($trans));
            } catch (\Exception $e) {
                $trans->state = 'error';
                $trans->exception = $e;
            }
            goto transition;
        }

        error: {
            try {
                // Convert non-request exception to a wrapped exception
                $trans->exception = RequestException::wrapException(
                    $trans->request, $trans->exception
                );
                $trans->state = 'end';
                $trans->request->getEmitter()->emit('error', new ErrorEvent($trans));
                // An intercepted request (not retried) transitions to complete
                if (!$trans->exception && $trans->state !== 'retry') {
                    $trans->state = 'complete';
                }
            } catch (\Exception $e) {
                $trans->state = 'end';
                $trans->exception = $e;
            }
            goto transition;
        }

        retry: {
            $trans->retries++;
            $trans->response = null;
            $trans->exception = null;
            $trans->state = 'before';
            goto transition;
        }

        send: {
            $fn = $this->handler;
            $trans->response = FutureResponse::proxy(
                $fn(RingBridge::prepareRingRequest($trans)),
                function ($value) use ($trans) {
                    RingBridge::completeRingResponse($trans, $value, $this->mf, $this);
                    $this($trans);
                    return $trans->response;
                }
            );
            return;
        }

        end: {
            $trans->request->getEmitter()->emit('end', new EndEvent($trans));
            // Throw exceptions in the terminal event if the exception
            // was not handled by an "end" event listener.
            if ($trans->exception) {
                if (!($trans->exception instanceof RequestException)) {
                    $trans->exception = RequestException::wrapException(
                        $trans->request, $trans->exception
                    );
                }
                throw $trans->exception;
            }
        }
    }
}
