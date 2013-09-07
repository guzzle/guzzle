<?php

namespace Guzzle\Stream;

use Guzzle\Stream\Php\DuplexStream;
use Guzzle\Stream\Php\ReadableStream;
use Guzzle\Stream\Php\WritableStream;

/**
 * Factory used to create streams
 */
class StreamFactory
{
    /** @var array Hash table of readable and writeable stream types for fast lookups */
    private static $readWriteHash = array(
        'read' => array(
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true,
            'rt' => true, 'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true, 'a+' => true
        ),
        'write' => array(
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'wb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true,
            'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true
        )
    );

    /**
     * Create a new stream based on the input type
     *
     * @param resource|string|StreamInterface $resource Entity body data
     * @param int                             $size     Size of the data contained in the resource
     *
     * @return StreamInterface
     * @throws \InvalidArgumentException if the $resource arg is not a resource or string
     */
    public static function create($resource = '', $size = null)
    {
        if ($resource instanceof StreamInterface) {
            return $resource;
        }

        switch (gettype($resource)) {
            case 'string':
                return self::fromString($resource);
            case 'resource':
                $meta = stream_get_meta_data($resource);
                $readable = isset(self::$readWriteHash['read'][$meta['mode']]);
                $writable = isset(self::$readWriteHash['write'][$meta['mode']]);
                if ($readable && $writable) {
                    return new DuplexStream($resource, $size, $meta);
                } elseif ($readable) {
                    return new ReadableStream($resource, $size, $meta);
                } elseif ($writable) {
                    return new WritableStream($resource, $size, $meta);
                }
                break;
            case 'object':
                if (method_exists($resource, '__toString')) {
                    return self::fromString((string) $resource);
                }
                break;
            case 'array':
                return self::fromString(http_build_query($resource));
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid resource type provided to %s: %s',
            __METHOD__,
            gettype($resource)
        ));
    }

    /**
     * Create a new stream from a string
     *
     * @param string $string String of data
     *
     * @return StreamInterface
     */
    protected static function fromString($string)
    {
        $stream = fopen('php://temp', 'r+');
        if ($string !== '') {
            fwrite($stream, $string);
            rewind($stream);
        }

        return new DuplexStream(
            $stream,
            strlen($string),
            stream_get_meta_data($stream)
        );
    }
}
