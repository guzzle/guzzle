<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class JsonEncodingTest extends TestCase
{
    private function createTestClient(array $config = []): Client
    {
        return new Client(array_merge([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200)
            ]))
        ], $config));
    }

    public function testClientLevelJsonEncoding()
    {
        $mock = new MockHandler([
            function (RequestInterface $request) {
                // Verify Content-Type header is set
                $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
                
                // Get the actual request body
                $body = (string) $request->getBody();
                $decoded = json_decode($body, true);
                
                // Verify the body structure
                $this->assertIsArray($decoded);
                $this->assertArrayHasKey('valid_utf8', $decoded);
                $this->assertArrayHasKey('invalid_utf8', $decoded);
                
                // Verify valid UTF-8 was preserved
                $this->assertEquals('👋 Hello', $decoded['valid_utf8']);
                
                // Verify invalid UTF-8 was replaced with the substitution character
                $this->assertStringContainsString('�', $decoded['invalid_utf8']);
                
                return new Response(200);
            }
        ]);

        $handler = HandlerStack::create($mock);
        
        // Configure client with JSON encoding options
        $client = new Client([
            'handler' => $handler,
            'json_encode_options' => JSON_INVALID_UTF8_SUBSTITUTE
        ]);

        $data = [
            'valid_utf8' => '👋 Hello',
            'invalid_utf8' => "Hello \xB1 World"  // Invalid UTF-8 sequence
        ];

        // The request should succeed because the client is configured to handle invalid UTF-8
        $response = $client->post('/', ['json' => $data]);
        $this->assertEquals(200, $response->getStatusCode());

        // Create a client without the encoding option
        $defaultClient = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);

        // This should throw due to invalid UTF-8
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('json_encode error');
        $defaultClient->post('/', ['json' => $data]);
    }

    public function testNestedInvalidUtf8()
    {
        $mock = new MockHandler([
            function (RequestInterface $request) {
                $body = (string) $request->getBody();
                $decoded = json_decode($body, true);
                
                // Verify nested invalid UTF-8 was handled
                $this->assertStringContainsString('�', $decoded['nested']['invalid']);
                $this->assertStringContainsString('�', $decoded['array'][0]);
                
                return new Response(200);
            }
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handler,
            'json_encode_options' => JSON_INVALID_UTF8_SUBSTITUTE
        ]);

        $data = [
            'nested' => [
                'invalid' => "Nested \xB1 Invalid"
            ],
            'array' => [
                "Array \xB1 Invalid"
            ]
        ];

        $response = $client->post('/', ['json' => $data]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testJsonEncodingOptionsInheritance()
    {
        $mock = new MockHandler([
            function (RequestInterface $request) {
                $body = (string) $request->getBody();
                $decoded = json_decode($body, true);
                
                // Unicode should not be escaped
                $this->assertStringContainsString('👋', $body);
                $this->assertEquals('👋 Hello', $decoded['greeting']);
                
                return new Response(200);
            }
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handler,
            'json_encode_options' => JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
        ]);

        $data = ['greeting' => '👋 Hello'];
        $response = $client->post('/', ['json' => $data]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testNestedUnicodeCharacters()
    {
        $mock = new MockHandler([
            function (RequestInterface $request) {
                $body = (string) $request->getBody();
                
                // Test that nested Unicode characters are preserved
                $this->assertStringContainsString('👨‍👩‍👧‍👦', $body); // Family emoji (multiple code points)
                $this->assertStringContainsString('🌍', $body); // Globe emoji
                $this->assertStringContainsString('⚡', $body); // Lightning bolt
                
                $decoded = json_decode($body, true);
                $this->assertEquals('👨‍👩‍👧‍👦', $decoded['nested']['family']);
                $this->assertEquals('🌍', $decoded['nested']['objects'][0]['location']);
                $this->assertEquals('⚡', $decoded['nested']['objects'][1]['power']);
                
                return new Response(200);
            }
        ]);

        $client = $this->createTestClient([
            'handler' => $mock,
            'json_encode_options' => JSON_UNESCAPED_UNICODE
        ]);

        $data = [
            'nested' => [
                'family' => '👨‍👩‍👧‍👦',
                'objects' => [
                    ['location' => '🌍'],
                    ['power' => '⚡']
                ]
            ]
        ];

        $response = $client->post('/', ['json' => $data]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testMixedEncodingOptions()
    {
        $mock = new MockHandler([
            function (RequestInterface $request) {
                $body = (string) $request->getBody();
                
                // Slashes should be escaped but Unicode shouldn't be
                $this->assertStringContainsString('\/', $body);
                $this->assertStringContainsString('👋', $body);
                $this->assertStringNotContainsString('\\u', $body);
                
                return new Response(200);
            }
        ]);

        $client = $this->createTestClient([
            'handler' => $mock,
            'json_encode_options' => JSON_UNESCAPED_UNICODE | ~JSON_UNESCAPED_SLASHES
        ]);

        $data = [
            'url' => 'http://example.com/path',
            'greeting' => '👋 Hello'
        ];

        $response = $client->post('/', ['json' => $data]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRequestLevelOptionsOverride()
    {
        $mock = new MockHandler([
            function (RequestInterface $request) {
                $body = (string) $request->getBody();
                
                // Unicode should be escaped (overriding client-level setting)
                $this->assertStringContainsString('\u', $body);
                $this->assertStringNotContainsString('👋', $body);
                
                return new Response(200);
            }
        ]);

        $client = $this->createTestClient([
            'handler' => $mock,
            'json_encode_options' => JSON_UNESCAPED_UNICODE
        ]);

        $data = ['greeting' => '👋 Hello'];
        
        // Override client-level options at request level
        $response = $client->post('/', [
            'json' => $data,
            'json_encode_options' => 0 // Default options (will escape Unicode)
        ]);
        
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testJsonOptionsCleanup()
    {
        $middlewareCalled = false;
        
        // Create a handler stack
        $stack = HandlerStack::create(new MockHandler([
            new Response(200)
        ]));
        
        // Add a middleware that checks the options
        $stack->push(function ($handler) use (&$middlewareCalled) {
            return function ($request, array $options) use ($handler, &$middlewareCalled) {
                // Verify json_encode_options is not present
                $this->assertArrayNotHasKey('json_encode_options', $options, 
                    'json_encode_options should be cleaned up before reaching other middleware');
                $middlewareCalled = true;
                return $handler($request, $options);
            };
        });

        $client = new Client([
            'handler' => $stack,
            'json_encode_options' => JSON_UNESCAPED_UNICODE
        ]);

        $data = ['test' => '👋'];
        
        // Make request with request-level options
        $response = $client->post('/', [
            'json' => $data,
            'json_encode_options' => 0
        ]);
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($middlewareCalled, 'Middleware was not called');
    }

    public function testSupplementaryPlaneCharacters()
    {
        $mock = new MockHandler([
            function (RequestInterface $request) {
                $body = (string) $request->getBody();
                $decoded = json_decode($body, true);
                
                // Test characters outside the Basic Multilingual Plane
                $this->assertStringContainsString('🎮', $body); // GAME CONTROLLER (U+1F3AE)
                $this->assertStringContainsString('🚀', $body); // ROCKET (U+1F680)
                $this->assertStringContainsString('🎨', $body); // ARTIST PALETTE (U+1F3A8)
                
                $this->assertEquals('🎮', $decoded['game']);
                $this->assertEquals('🚀', $decoded['rocket']);
                $this->assertEquals('🎨', $decoded['art']);
                
                return new Response(200);
            }
        ]);

        $client = $this->createTestClient([
            'handler' => $mock,
            'json_encode_options' => JSON_UNESCAPED_UNICODE
        ]);

        $data = [
            'game' => '🎮',
            'rocket' => '🚀',
            'art' => '🎨'
        ];

        $response = $client->post('/', ['json' => $data]);
        $this->assertEquals(200, $response->getStatusCode());
    }
}