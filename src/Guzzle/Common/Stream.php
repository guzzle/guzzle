<?php

namespace Guzzle\Common;

/**
 * OO interface to PHP streams
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Stream
{
    /**
     * @var resource Stream resource
     */
    protected $stream;

    /**
     * @var bool If the stream is seekable
     */
    protected $seekable;

    /**
     * @var string The stream wrapper type
     */
    protected $wrapper;

    /**
     * @var string The mode in which the stream was opened (r, w, r+, etc)
     */
    protected $mode;

    /**
     * @var string URI of the stream (a filename, URL, etc)
     */
    protected $uri;

    /**
     * @var string Label describing the underlying implementation of the stream
     */
    protected $type;

    /**
     * @var int Size of the stream contents in bytes
     */
    protected $size;

    /**
     * @var array Array of filters
     */
    protected $filters = array();

    /**
     * Construct a new Stream
     *
     * @param resource $stream Stream resource to wrap
     * @param int $size (optional) Size of the stream in bytes.  Only pass this
     *      parameter if the size cannot be obtained from the stream.
     * 
     * @throws InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($stream, $size = null)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException(
                'Invalid $stream argument sent to ' . __METHOD__
            );
        }

        $this->stream = $stream;
        $meta = stream_get_meta_data($stream);
        $this->wrapper = isset($meta['wrapper_type']) ? strtolower($meta['wrapper_type']) : '';
        $this->mode = isset($meta['mode']) ? $meta['mode'] : '';
        $this->seekable = isset($meta['seekable']) ? $meta['seekable'] : false;
        $this->uri = isset($meta['uri']) ? $meta['uri'] : '';
        $this->type = isset($meta['stream_type']) ? strtolower($meta['stream_type']) : '';
        $this->size = $size;
    }

    /**
     * Closes the stream when the helper is destructed
     */
    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Convert the stream to a string if the stream is readable and the stream
     * is seekable.  This logic is enforced to ensure that outputting the stream
     * as a string does not affect an actual cURL request using non-repeatable
     * streams.
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->isReadable() || (!$this->isSeekable() && $this->isConsumed())) {
            return '';
        }

        $this->seek(0);
        $body = stream_get_contents($this->stream);
        $this->seek(0);

        return $body;
    }

    /**
     * Get the stream resource
     *
     * @return resource
     */
    public function getStream()
    {
        return $this->stream;;
    }

    /**
     * Get the stream wrapper type
     *
     * @return string
     */
    public function getWrapper()
    {
        return $this->wrapper;
    }

    /**
     * Wrapper specific data attached to this stream.
     *
     * @return string
     */
    public function getWrapperData()
    {
        $meta = stream_get_meta_data($this->stream);

        return isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
    }

    /**
     * Get a label describing the underlying implementation of the stream
     *
     * @return string
     */
    public function getStreamType()
    {
        return $this->type;
    }

    /**
     * Get the URI/filename associated with this stream
     *
     * @return string
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Get the size of the stream if able
     *
     * If any filters are attached to the string, then the size
     *
     * @return int|false
     */
    public function getSize()
    {
        if ($this->size !== null) {
            return $this->size;
        }

        // Only get the length if there are no modifying filters attached to
        // the stream that would modify the stream on the fly
        $filters = $this->getFilters();
        $filters = array_keys($filters['wrapped']);
        if (count(array_filter($filters, function($filter) {
            $filter = explode('|', $filter);
            $filter = $filter[0];
            return !in_array($filter, array('string.rot13', 'string.toupper', 'string.tolower'));
        }))) {
            return false;
        }

        // If the stream is a file based stream and local, then check the filesize
        if ($this->isLocal() && $this->getWrapper() == 'plainfile' && $this->getUri() && file_exists($this->getUri())) {
            return filesize($this->getUri());
        }

        // Only get the size based on the content if the the stream is readable
        // and seekable so as to not interfere with actually reading the data
        if (!$this->isReadable() || !$this->isSeekable()) {
            return false;
        } else {
            $size = strlen((string) $this);
            $this->seek(0);

            return $size;
        }
    }

    /**
     * Check if the stream is readable
     *
     * @return bool
     */
    public function isReadable()
    {
        return in_array(str_replace('b', '', $this->mode), array('r', 'w+', 'r+', 'x+', 'c+'));
    }

    /**
     * Check if the stream is writable
     *
     * @return bool
     */
    public function isWritable()
    {
        return str_replace('b', '', $this->mode) != 'r';
    }

    /**
     * Check if the stream has been consumed
     *
     * @return bool
     */
    public function isConsumed()
    {
        return feof($this->stream);
    }

    /**
     * Check if the stream is a local stream vs a remote stream
     *
     * @return bool
     */
    public function isLocal()
    {
        return stream_is_local($this->stream);
    }

    /**
     * Check if the string is repeatable
     *
     * @return bool
     */
    public function isSeekable()
    {
        return $this->seekable;
    }

    /**
     * Specify the size of the stream in bytes
     *
     * @param int $size Size of the stream contents in bytes
     *
     * @return Stream
     */
    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Seek to a position in the stream
     *
     * @param int $offset Stream offset
     * @param int $whence (optional) Where the offset is applied
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @see http://www.php.net/manual/en/function.fseek.php
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->isSeekable() ? fseek($this->stream, $offset, $whence) === 0 : false;
    }

    /**
     * Read data from the stream
     *
     * @param int $length Up to length number of bytes read.
     *
     * @return string|bool Returns the data read from the stream or FALSE on
     *      failure or EOF
     */
    public function read($length)
    {
        return $this->isReadable() ? fread($this->stream, $length) : false;
    }

    /**
     * Write data to the stream
     *
     * @param string $string The string that is to be written.
     *
     * @return int|bool Returns the number of bytes written to the stream on
     *      success or FALSE on failure.
     */
    public function write($string)
    {
        return $this->isWritable() ? fwrite($this->stream, $string) : false;
    }

    /**
     * Add a filter to the stream
     *
     * @param string $filter Name of the filter to append
     * @param int $readWrite (optional) By default, stream_filter_append() will
     *      attach the filter to the read filter chain if the file was opened
     *      for reading (i.e. File Mode: r, and/or +). The filter will also be
     *      attached to the write filter chain if the file was opened for
     *      writing (i.e. File Mode: w, a, and/or +). STREAM_FILTER_READ,
     *      STREAM_FILTER_WRITE, and/or STREAM_FILTER_ALL can also be passed
     *      to the read_write parameter to override this behavior.
     * @param array $options (optional) This filter will be added with the
     *      specified params to the end of the list and will therefore be
     *      called last during stream operations. To add a filter to the
     *      beginning of the list, use stream_filter_prepend().
     * @param bool $prepend (optional) Set to TRUE to prepend the filter.
     *      Default is to append
     *
     * @return Stream
     */
    public function addFilter($filter, $readWrite = STREAM_FILTER_ALL, $options = null, $prepend = false)
    {
        if ($prepend) {
            $resource = stream_filter_prepend($this->stream, $filter, $readWrite, $options);
        } else {
            $resource = stream_filter_append($this->stream, $filter, $readWrite, $options);
        }

        $this->filters[$filter . '|' . $readWrite] = $resource;

        return $this;
    }

    /**
     * Get an array of filters added to the stream
     *
     * @return array Returns an associative array containing the following keys:
     *      wrapped - Filters that were applied using the helper
     *          (associative array of [filter name] + [.] + [read write value] => resource
     *      unwrapped - Filters that were not applied using the helper
     */
    public function getFilters()
    {
        $meta = stream_get_meta_data($this->stream);

        return array(
            'wrapped' => $this->filters,
            'unwrapped' => isset($meta['filters']) ? $meta['filters'] : array()
        );
    }

    /**
     * Remove a filter from the stream
     *
     * @param string $filter Filter to remove by name
     * @param $readWrite int (optional) Which type of filter to remove if
     *      removing filters by name using a string
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function removeFilter($filter, $readWrite = STREAM_FILTER_ALL)
    {
        $key = $filter . '|' . $readWrite;

        if (isset($this->filters[$key])) {
            stream_filter_remove($this->filters[$key]);
            unset($this->filters[$key]);

            return true;
        }

        return false;
    }
}