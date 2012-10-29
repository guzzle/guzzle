<?php

namespace Guzzle\Tests\Parsers\UriTemplate;

use Guzzle\Parser\UriTemplate\PeclUriTemplate;

/**
 * @covers Guzzle\Parser\UriTemplate\PeclUriTemplate
 */
class PeclUriTemplateTest extends AbstractUriTemplateTest
{
    /**
     * @dataProvider templateProvider
     */
    public function testExpandsUriTemplates($template, $expansion, $params)
    {
        $uri = new PeclUriTemplate($template);
        $this->assertEquals($expansion, $uri->expand($template, $params));
    }
}
