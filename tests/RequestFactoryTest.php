<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\RequestFactory;
use PHPUnit\Framework\TestCase;

class RequestFactoryTest extends TestCase
{


    public function testOptionsArrayModified()
    {
        $factory = new RequestFactory();

        $options = [
            'form_params'   =>  ['one' => 1],
            'sink'          =>  'ok',
        ];
        $factory->createRequest('POST', 'http://example.com', $options);

        $expected = [
            'sink'          =>  'ok',
        ];

        $this->assertSame($expected, $options);
    }


    public function testOptionsArrayUnmodified()
    {
        $options = [
            'form_params'   =>  ['one' => 1],
            'sink'          =>  'ok',
        ];
        $expected = $options;
        RequestFactory::create('POST', 'http://example.com', $options);

        $this->assertSame($expected, $options);
    }
}
