<?php

namespace GuzzleHttp\Encoder;

interface JsonEncoderInterface
{
    /**
     * @param mixed $value
     * @param int   $options
     * @param int   $depth
     * @return string
     */
    public function encode($value, int $options = 0, int $depth = 512): string;

    /**
     * @param string $json
     * @param bool   $assoc
     * @param int    $depth
     * @param int    $options
     * @return mixed
     */
    public function decode(string $json, bool $assoc = false, int $depth = 512, int $options = 0);
}
