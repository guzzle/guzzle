<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\Client;

/**
 * @covers Guzzle\Http\Client
 */
class ClientTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testProvidesDefaultUserAgent()
    {
        $this->assertEquals(1, preg_match('#^Guzzle/.+ curl/.+ PHP/.+$#', Client::getDefaultUserAgent()));
    }

    public function testUsesDefaultDefaultOptions()
    {

    }

    public function testUsesProvidedDefaultOptions()
    {

    }

    public function testCanSpecifyBaseUrl()
    {

    }

    public function testCanSpecifyBaseUrlUriTemplate()
    {

    }

    public function testClientUsesDefaultAdapterWhenNoneIsSet()
    {

    }

    public function testCanSpecifyAdapter()
    {

    }

    public function testCanSpecifyMessageFactory()
    {

    }

    public function testAddsDefaultUserAgentHeaderWithDefaultOptions()
    {

    }

    public function testAddsDefaultUserAgentHeaderWithoutDefaultOptions()
    {

    }

    public function testProvidesConfigPathValues()
    {

    }

    public function testClientProvidesDefaultOptionPath()
    {

    }

    public function testClientProvidesMethodShortcuts()
    {

    }

    public function testClientMergesDefaultOptionsWithRequestOptions()
    {

    }

    public function testCreatedRequestsUseCloneOfClientEventDispatcher()
    {

    }

    public function testUsesBaseUrlWhenNoUrlIsSet()
    {

    }

    public function testUsesBaseUrlCombinedWithProvidedUrl()
    {

    }

    public function testSettingAbsoluteUrlOverridesBaseUrl()
    {

    }

    public function testEmitsCreateRequestEvent()
    {

    }

    public function testClientSendsRequests()
    {

    }

    public function testSendingRequestCanBeIntercepted()
    {

    }
}
