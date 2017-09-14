<?php

namespace GuzzleHttp;

use Psr\Http\Message\RequestInterface;

/**
 * Interface for generating HTTP requests.
 */
interface RequestFactoryInterface
{

    /**
     * Applies any URI options to an existing URI to generate a new one.
     *
     * @param string $uri
     * @param array  $config
     *
     * @return string
     */
    public function createUri($uri, array $config);


    /**
     * Create a new request.
     *
     * The $options array is passed by reference so that any
     * options that are applied to the request are then removed
     * from the array to avoid them being applied twice.
     *
     * @param string $method
     * @param string $uri
     * @param array  $options
     *
     * @return RequestInterface
     */
    public function createRequest($method, $uri = '', array &$options = []);


    /**
     * Applies the array of request options to a request.
     *
     * The $options array is passed by reference so that any
     * options that are applied to the request are then removed
     * from the array to avoid them being applied twice.
     *
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return RequestInterface
     */
    public function applyOptions(RequestInterface $request, array &$options);
}
