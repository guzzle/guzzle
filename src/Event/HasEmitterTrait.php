<?php

namespace GuzzleHttp\Event;

/**
 * Trait that implements the methods of HasEmitterInterface
 */
trait HasEmitterTrait
{
    /** @var EmitterInterface */
    private $emitter;

    public function getEmitter()
    {
        if (!$this->emitter) {
            $this->emitter = new Emitter();
        }

        return $this->emitter;
    }
}
