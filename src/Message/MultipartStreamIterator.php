<?php

namespace GuzzleHttp\Message;

use \Iterator;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Multipart stream iterator
 *
 * @author Fabio B. Silva <fabio.bat.silva@gmail.com>
 */
class MultipartStreamIterator implements Iterator
{
    /**
     * @var \GuzzleHttp\Stream\StreamInterface
     */
    private $stream;

    /**
     * @var integer
     */
    private $count = 0;

    /**
     * @var \GuzzleHttp\Stream\StreamInterface
     */
    private $current;

    /**
     * @var string
     */
    private $boundaryItem;

    /**
     * @var string
     */
    private $boundaryEnd;

    /**
     * @param \GuzzleHttp\Stream\StreamInterface $stream
     * @param string                             $boundary
     */
    public function __construct(StreamInterface $stream, $boundary)
    {
        if ($boundary == null) {
            throw new \InvalidArgumentException("Boundary cannot be null");
        }

        $this->stream       = $stream;
        $this->boundaryItem = "--$boundary";
        $this->boundaryEnd  = "--$boundary--";
    }

    /**
     * @return string
     */
    private function readLine()
    {
        $buffer = '';

        while ( ! $this->stream->eof()) {

            $buffer .= $this->stream->read(1);

            if (substr($buffer, -2) !== "\r\n") {
                continue;
            }

            return substr($buffer, 0, -2);
        }

        return $buffer;
    }

    /**
     * @param string $line
     *
     * @return boolean
     */
    private function isBoundary($line)
    {
        if ($line === $this->boundaryItem) {
            return true;
        }

        if ($line === $this->boundaryEnd) {
            return true;
        }

        return false;
    }

    /**
     * Move to next boundary
     */
    private function moveToNext()
    {
        while ( ! $this->stream->eof()) {
            if ($this->isBoundary($line = $this->readLine())) {
                break;
            }
        }
    }

    /**
     * @return \GuzzleHttp\Stream\StreamInterface
     */
    private function readNext()
    {
        $line   = null;
        $stream = Stream::factory();

        while ( ! $this->stream->eof()) {

            if ($this->isBoundary($line = $this->readLine())) {
                break;
            }

            $stream->write("\r\n" . $line);
        }

        if ($line === null) {
            return null;
        }

        $stream->seek(0);

        return $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        return $this->current;
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return $this->count;
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        $this->count ++;
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        if (($this->current = $this->readNext()) !== null) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        $this->stream->seek(0);

        $this->count = 0;

        $this->moveToNext();
    }
}
