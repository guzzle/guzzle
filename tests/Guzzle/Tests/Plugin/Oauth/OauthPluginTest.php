<?php

namespace Guzzle\Tests\Plugin\Oauth;

use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Plugin\Oauth\OauthPlugin;
use Guzzle\Common\Event;

/**
 * @covers Guzzle\Plugin\Oauth\OauthPlugin
 */
class OauthPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    const TIMESTAMP = '1327274290';
    const NONCE = 'e7aa11195ca58349bec8b5ebe351d3497eb9e603';

    protected $config = array(
        'consumer_key'    => 'foo',
        'consumer_secret' => 'bar',
        'token'           => 'count',
        'token_secret'    => 'dracula'
    );

    /**
     * @return RequestInterface
     */
    protected function getRequest()
    {
        return RequestFactory::getInstance()->create('POST', 'http://www.test.com/path?a=b&c=d', null, array(
            'e' => 'f'
        ));
    }

    public function testSubscribesToEvents()
    {
        $this->assertArrayHasKey('request.before_send', OauthPlugin::getSubscribedEvents());
    }

    public function testAcceptsConfigurationData()
    {
        $p = new OauthPlugin($this->config);

        // Access the config object
        $class = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($p);

        $this->assertEquals('foo', $config['consumer_key']);
        $this->assertEquals('bar', $config['consumer_secret']);
        $this->assertEquals('count', $config['token']);
        $this->assertEquals('dracula', $config['token_secret']);
        $this->assertEquals('1.0', $config['version']);
        $this->assertEquals('HMAC-SHA1', $config['signature_method']);
        $this->assertEquals('header', $config['request_method']);
    }

    public function testSignsOauthQueryStringRequest()
    {
        $config = array_merge(
            $this->config,
            array('request_method' => OauthPlugin::REQUEST_METHOD_QUERY)
        );

        $p = new OauthPlugin($config);
        $event = new Event(array(
            'request' => $this->getRequest(),
            'timestamp' => self::TIMESTAMP
        ));
        $params = $p->onRequestBeforeSend($event);

        $this->assertFalse($event['request']->hasHeader('Authorization'));

        $stringsToCheck = array(
            'a=b',
            'c=d',
            'oauth_consumer_key=foo',
            'oauth_nonce='.urlencode($params['oauth_nonce']),
            'oauth_signature='.urlencode($params['oauth_signature']),
            'oauth_signature_method=HMAC-SHA1',
            'oauth_timestamp='.self::TIMESTAMP,
            'oauth_token=count',
            'oauth_version=1.0',
        );

        $queryString = (string) $event['request']->getQuery();

        $totalLength = strlen('?');

        //Separator is not used before first parameter.
        $separator = '';

        foreach ($stringsToCheck as $stringToCheck) {
            $this->assertContains($stringToCheck, $queryString);
            $totalLength += strlen($separator);
            $totalLength += strlen($stringToCheck);
            $separator = '&';
        }

        // Removes the last query string separator '&'
        $totalLength -= 1;

        $this->assertEquals($totalLength, strlen($queryString), 'Query string has extra characters i.e. contains extra elements compared to stringsToCheck.');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testInvalidArgumentExceptionOnMethodError()
    {
        $config = array_merge(
            $this->config,
            array('request_method' => 'FakeMethod')
        );

        $p = new OauthPlugin($config);
        $event = new Event(array(
            'request' => $this->getRequest(),
            'timestamp' => self::TIMESTAMP
        ));

        $p->onRequestBeforeSend($event);
    }

    public function testDoesNotAddFalseyValuesToAuthorization()
    {
        unset($this->config['token']);
        $p = new OauthPlugin($this->config);
        $event = new Event(array('request' => $this->getRequest(), 'timestamp' => self::TIMESTAMP));
        $p->onRequestBeforeSend($event);

        $this->assertTrue($event['request']->hasHeader('Authorization'));
        $this->assertNotContains('oauth_token=', (string) $event['request']->getHeader('Authorization'));
    }


    /**
     * @dataProvider provideValidData
     */
    public function testSignatureIsGeneratedCorrectly($signature, $url)
    {
        $request = $this->getRequest();
        $request->setUrl($url);

        // Parameters from http://oauth.net/core/1.0a/#anchor46
        $p = new OauthPlugin(array(
            'oauth_consumer_key'     => 'dpf43f3p2l4k3l03',
            'oauth_token'            => 'nnch734d00sl2jdk',
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => '1191242096',
            'oauth_nonce'            => 'kllo9940pd9333jh',
            'oauth_version'          => '1.0',
        ));

        $this->assertEquals(
            $signature,
            $p->getSignature($request, 'kd94hf93k423kf44', 'pfkkdhi9sl3r4s00')
        );
    }

    public function provideValidData()
    {
        return array(
            array('iflJZCKxEsZ58FFDyCysxfLbuKM=', 'http://photos.example.net/photos'),
            array('tR3+Ty81lMeYAr/Fid0kMTYa/WM=', 'http://photos.example.net/photos?file=vacation.jpg&size=original'),
        );
    }
}
