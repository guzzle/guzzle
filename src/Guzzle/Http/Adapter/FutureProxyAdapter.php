<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Message\FutureResponse;

/**
 * Implements 'future' requests for any adapter
 */
class FutureProxyAdapter implements AdapterInterface
{
    private $defaultAdapter;
    private $futureAdapter;

    /**
     * @param AdapterInterface $defaultAdapter Adapter used for regulate requests
     * @param AdapterInterface $futureAdapter  Adapter used for future requests
     */
    public function __construct(AdapterInterface $defaultAdapter, AdapterInterface $futureAdapter = null)
    {
        $this->defaultAdapter = $defaultAdapter;
        $this->futureAdapter = $futureAdapter ?: $defaultAdapter;
    }

    public function send(TransactionInterface $transaction)
    {
        if ($transaction->getRequest()->getConfig()['future']) {
            $transaction->getRequest()->getConfig()->set('future', false);
            $response = new FutureResponse($transaction, $this->futureAdapter);
            $transaction->setResponse($response);
            return $response;
        }

        return $this->defaultAdapter->send($transaction);
    }
}
