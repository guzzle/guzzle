<?php

namespace Guzzle\Plugin\Log;

use Psr\Log\LoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Simple logger implementation that can write to a function or resource
 */
class SimpleLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var mixed Where data is written */
    private $writeTo;

    public function __construct($writeTo = null)
    {
        $this->writeTo = $writeTo;
    }

    public function log($level, $message, array $context = array())
    {
        if (is_resource($this->writeTo)) {
            fwrite($this->writeTo, "[{$level}] {$message}\n");
        } elseif (is_callable($this->writeTo)) {
            call_user_func($this->writeTo, "[{$level}] {$message}\n");
        } else {
            echo "[{$level}] {$message}\n";
        }
    }
}
