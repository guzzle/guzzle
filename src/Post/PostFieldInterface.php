<?php

namespace GuzzleHttp\Post;

/**
 * Interface post field interface
 */
interface PostFieldInterface extends PostElementInterface
{
    /**
     * Get the name of the field
     *
     * @return string
     */
    public function getName();

    /**
     * Get the value of the field
     *
     * @return string
     */
    public function getValue();

    /**
     * Return the value (alias of getValue)
     *
     * @return string
     */
    public function __toString();
}
