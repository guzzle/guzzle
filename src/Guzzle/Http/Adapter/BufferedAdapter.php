<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Adapter\Transaction;

/**
 * Adapter that does not attempt to send more than a certain number of requests
 * in parallel
 */
class BufferedAdapter implements AdapterInterface
{
    protected $adapter;
    protected $max;

    /**
     * @param AdapterInterface $adapter Adapter used for sending requests
     * @param int              $max     Maximum number of requests per batch
     */
    public function __construct(AdapterInterface $adapter, $max = 20)
    {
        $this->adapter = $adapter;
        $this->max = $max;
    }

    public function send(array $requests)
    {
        $result = new Transaction();
        $c = 0;
        $buffer = [];

        foreach ($requests as $request) {
            $buffer[] = $request;
            if (++$c >= $this->max) {
                $result->addAll($this->adapter->send($buffer));
                $buffer = [];
                $c = 0;
            }
        }

        if ($buffer) {
            $result->addAll($this->adapter->send($buffer));
        }

        return $result;
    }
}
