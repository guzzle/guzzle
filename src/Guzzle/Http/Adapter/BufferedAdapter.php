<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Adapter\Transaction;

/**
 * Adapter that does not attempt to send more than a certain number of requests
 * in parallel
 */
class BufferedAdapter implements AdapterInterface
{
    private $adapter;
    private $max;

    /**
     * @param AdapterInterface $adapter Adapter used for sending requests
     * @param int              $max     Maximum number of requests per batch
     */
    public function __construct(AdapterInterface $adapter, $max = 20)
    {
        $this->adapter = $adapter;
        $this->max = $max;
    }

    public function send(Transaction $transaction)
    {
        $bufferCount = 0;
        $buffer = new Transaction($transaction->getClient());

        foreach ($transaction as $request) {
            $buffer[$request] = $transaction[$request];
            if (++$bufferCount >= $this->max) {
                $transaction->addAll($this->adapter->send($buffer));
                $buffer = new Transaction($transaction->getClient());
                $bufferCount = 0;
            }
        }

        if ($bufferCount > 0) {
            $transaction->addAll($this->adapter->send($buffer));
        }

        return $transaction;
    }
}
