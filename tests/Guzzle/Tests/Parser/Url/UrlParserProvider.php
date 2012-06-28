<?php

namespace Guzzle\Tests\Parser\Url;

class UrlParserProvider extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @return array
     */
    public function urlProvider()
    {
        $resp = array();
        foreach (array(
            'http://www.guzzle-project.com/',
            'http://www.google.com:8080/path?q=1&v=2',
            'https://www.guzzle-project.com/?value1=a&value2=b',
            'https://guzzle-project.com/index.html',
            '/index.html?q=2',
            'http://www.google.com:8080/path?q=1&v=2',
            'http://michael:123@www.google.com:8080/path?q=1&v=2',
            'http://michael@test.com/abc/def?q=10#test'
        ) as $url) {
            $parts = parse_url($url);
            $resp[] = array($url, parse_url($url));
        }

        return $resp;
    }
}
