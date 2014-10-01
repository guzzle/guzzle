<?php
/*
 * This file is here for backwards compatibility with Guzzle 4. Use the
 * functions available on GuzzleHttp\Utils instead.
 */

namespace GuzzleHttp;

if (!defined('GUZZLE_FUNCTIONS_VERSION')) {

    define('GUZZLE_FUNCTIONS_VERSION', ClientInterface::VERSION);

    /**
     * @param ClientInterface $client
     * @param array           $requests
     * @param array           $options
     * @return \SplObjectStorage For backwards compatibility with v4
     * @deprecated Use GuzzleHttp\Pool::batch
     */
    function batch(ClientInterface $client, $requests, array $options = [])
    {
        $result = Pool::batch($client, $requests, $options);
        $hash = new \SplObjectStorage();
        foreach ($result->getKeys() as $request) {
            $hash[$request] = $result->getResult($request);
        }

        return $hash;
    }

    /**
     * @deprecated Use GuzzleHttp\Utils::getPath
     */
    function get_path($data, $path)
    {
        return Utils::getPath($data, $path);
    }

    /**
     * @deprecated Use GuzzleHttp\Utils::setPath
     */
    function set_path(&$data, $path, $value)
    {
        Utils::setPath($data, $path, $value);
    }

    /**
     * @deprecated Use GuzzleHttp\Utils::uriTemplate
     */
    function uri_template($template, array $variables)
    {
        return Utils::uriTemplate($template, $variables);
    }

    /**
     * @deprecated Use GuzzleHttp\Utils::jsonDecode
     */
    function json_decode($json, $assoc = false, $depth = 512, $options = 0)
    {
        return Utils::jsonDecode($json, $assoc, $depth, $options);
    }
}
