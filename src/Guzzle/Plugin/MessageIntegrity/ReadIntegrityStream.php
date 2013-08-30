<?php

namespace Guzzle\Plugin\MessageIntegrity;

use Guzzle\Stream\StreamInterface;
use Guzzle\Stream\StreamDecorator;
use Guzzle\Common\HasDispatcherTrait;
use Guzzle\Common\HasDispatcherInterface;

/**
 * Stream decorator that validates a rolling hash of the entity body as it is read
 * @todo Allow the file pointer to skip around and read bytes randomly
 */
class ReadIntegrityStream implements StreamInterface, HasDispatcherInterface
{
    use StreamDecorator, HasDispatcherTrait;

    /** @var HashInterface */
    private $hash;
    /** @var callable */
    private $validationCallback;
    /** @var int Last position that the hash was updated at */
    private $lastHashPos = 0;

    public function __construct(StreamInterface $stream, HashInterface $hash, callable $validationCallback)
    {
        $this->stream = $stream;
        $this->hash = $hash;
        $this->validationCallback = $validationCallback;
    }

    public function read($length)
    {
        $data = $this->stream->read($length);
        // Only update the hash if this data has not already been read
        if ($this->tell() >= $this->lastHashPos) {
            $this->hash->update($data);
            $this->lastHashPos += $length;
            if ($this->eof()) {
                $callback = $this->validationCallback;
                $callback(base64_encode($this->hash->complete()));
            }
        }
    }
}
