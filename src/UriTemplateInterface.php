<?php

namespace GuzzleHttp;

/**
 * An interface for URI template
 */
interface UriTemplateInterface
{
    /**
     * @param string $template
     * @param array  $variables
     *
     * @return string
     */
    public function expand($template, array $variables);
}
