<?php

namespace Guzzle\Http\Message\Form;

use Guzzle\Http\Message\HasHeadersInterface;
use Guzzle\Stream\StreamInterface;

/**
 * Form file upload interface
 */
interface FormFileInterface extends HasHeadersInterface
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
