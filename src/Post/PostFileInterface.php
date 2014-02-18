<?php

namespace GuzzleHttp\Post;

use GuzzleHttp\Message\HasHeadersInterface;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Post file upload interface
 */
interface PostFileInterface extends HasHeadersInterface
{
    /**
     * Get the name of the form field
     *
     * @return string
     */
    public function getName();

    /**
     * Get the full path to the file
     *
     * @return string
     */
    public function getFilename();

    /**
     * Get the content
     *
     * @return StreamInterface
     */
    public function getContent();
}
