<?php

namespace Guzzle\Tests\Http\Message\Header;

use Guzzle\Http\Message\Header\Link;
use Guzzle\Tests\GuzzleTestCase;

class LinkTest extends GuzzleTestCase
{
    public function testParsesLinks()
    {
        $link = new Link('Link', '<http:/.../front.jpeg>; rel=front; type="image/jpeg", <http://.../back.jpeg>; rel=back; type="image/jpeg"');
        $links = $link->getLinks();
        $this->assertEquals(array(
            array(
                'rel' => 'front',
                'type' => 'image/jpeg',
                'url' => 'http:/.../front.jpeg',
            ),
            array(
                'rel' => 'back',
                'type' => 'image/jpeg',
                'url' => 'http://.../back.jpeg',
            ),
        ), $links);

        $this->assertEquals(array(
            'rel' => 'back',
            'type' => 'image/jpeg',
            'url' => 'http://.../back.jpeg',
        ), $link->getLink('back'));

        $this->assertTrue($link->hasLink('front'));
        $this->assertFalse($link->hasLink('foo'));
    }
}
