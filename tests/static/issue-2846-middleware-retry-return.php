<?php

declare(strict_types=1);

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$stack = new HandlerStack();

$stack->push(Middleware::retry(static function () {
}));
