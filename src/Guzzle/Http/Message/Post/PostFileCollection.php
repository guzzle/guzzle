<?php

namespace Guzzle\Http\Message\Post;

/**
 * Holds a collection of form files to be sent in a request
 */
class PostFileCollection implements \Countable, \IteratorAggregate
{
    /** @var array */
    private $files;

    /**
     * Add a file to the collection
     *
     * @param PostFileInterface $file File to add
     */
    public function addFile(PostFileInterface $file)
    {
        $this->files[] = $file;
    }

    /**
     * Remove all files from the collection
     */
    public function clearFiles()
    {
        $this->files = [];
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->files);
    }

    public function count()
    {
        return count($this->files);
    }
}
