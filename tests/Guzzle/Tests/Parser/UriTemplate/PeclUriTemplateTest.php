<?php

namespace Guzzle\Tests\Parsers\UriTemplate;

use Guzzle\Parser\UriTemplate\PeclUriTemplate;

/**
 * @covers Guzzle\Parser\UriTemplate\PeclUriTemplate
 */
class PeclUriTemplateTest extends AbstractUriTemplateTest
{
    public function setUp()
    {
        if (!extension_loaded('uri_template')) {
            $this->markTestSkipped(
                'The PECL uri_template extension is not loaded.'
            );
        }

        parent::setUp();
    }
    /**
     * @dataProvider templateProvider
     */
    public function testExpandsUriTemplates($template, $expansion, $params)
    {
        $uri = new PeclUriTemplate($template);
        $this->assertEquals($expansion, $uri->expand($template, $params));
    }
}
