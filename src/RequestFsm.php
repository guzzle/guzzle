<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\StateException;
use GuzzleHttp\Ring\FutureInterface;

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
                'success'    => 'end',
                'error'      => 'error',
                'transition' => [$this, 'completeTransition']
            ],
            'error' => [
                'success'    => 'complete',
                'error'      => 'end',
                'transition' => [$this, 'ErrorTransition']
            ],
            'end' => [
                'transition' => [$this, 'endTransition']
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
        // Futures will have their own end events emitted when dereferenced.
        if ($trans->response instanceof FutureInterface) {
            return;
        }

        if (!$trans->response) {
            throw new StateException('Invalid complete state: no response');
        }

        $trans->response->setEffectiveUrl($trans->request->getUrl());
        $trans->request->getEmitter()->emit(
            'complete',
            new CompleteEvent($trans)
        );
    }

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
}
