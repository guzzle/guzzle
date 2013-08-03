<?php

namespace Guzzle\Http\Message\Form;

/**
 * Holds a collection of form files to be sent in a request
 */
class FormFileCollection implements \Countable, \IteratorAggregate
{
    /** @var array */
    private $files;

    /**
     * Add a file to the collection
     *
     * @param FormFileInterface $file File to add
     */
    public function addFile(FormFileInterface $file)
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
