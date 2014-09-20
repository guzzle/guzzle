<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Fsm;
use GuzzleHttp\Transaction;
use GuzzleHttp\Exception\RequestException;

/**
 * Defines the state transitions of a request and its state transitions.
 */
class RequestFsm extends Fsm
{
    public function __construct()
    {
        parent::__construct('before', [
            'before'   => [
                'success'    => 'send',
                'error'      => 'error',
                'transition' => [$this, 'beforeTransition']
            ],
            'send' => [
                'success' => 'complete',
                'error'   => 'error'
            ],
            'complete' => [
                'success'    => 'done',
                'error'      => 'error',
                'transition' => [$this, 'completeTransition']
            ],
            'error' => [
                'success'    => 'complete',
                'error'      => 'done',
                'transition' => [$this, 'ErrorTransition']
            ],
            'done' => [
                'transition' => [$this, 'doneTransition']
            ]
        ]);
    }

    protected function beforeTransition(Transaction $transaction)
    {
        $transaction->request->getEmitter()->emit(
            'before',
            new BeforeEvent($transaction)
        );
    }

    protected function errorTransition(Transaction $transaction)
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

    protected function completeTransition(Transaction $trans)
    {
        $trans->response->setEffectiveUrl($trans->request->getUrl());
        $trans->request->getEmitter()->emit(
            'complete',
            new CompleteEvent($trans)
        );
    }

    protected function doneTransition(Transaction $trans)
    {
        $trans->request->getEmitter()->emit('done', new DoneEvent($trans));
    }
}
