<?php

namespace Guzzle\Stream;

/**
 * Streams that can be written to and read from.
 */
interface DuplexStreamInterface extends
    ReadableStreamInterface,
    WritableStreamInterface
{}
