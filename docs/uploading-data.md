# Uploading Data

Guzzle provides several methods for uploading data.

You can send requests that contain a stream of data by passing a string,
resource returned from `fopen`, or an instance of a
`Psr\Http\Message\StreamInterface` to the `body` request option.

```php
use GuzzleHttp\Psr7;

// Provide the body as a string.
$r = $client->request('POST', 'http://httpbin.org/post', [
    'body' => 'raw data'
]);

// Provide an fopen resource.
$body = Psr7\Utils::tryFopen('/path/to/file', 'r');
$r = $client->request('POST', 'http://httpbin.org/post', ['body' => $body]);

// Use the Utils::streamFor method to create a PSR-7 stream.
$body = Psr7\Utils::streamFor('hello!');
$r = $client->request('POST', 'http://httpbin.org/post', ['body' => $body]);
```

An easy way to upload JSON data and set the appropriate header is using the
`json` request option:

```php
$r = $client->request('PUT', 'http://httpbin.org/put', [
    'json' => ['foo' => 'bar']
]);
```

## POST/Form Requests

In addition to specifying the raw data of a request using the `body` request
option, Guzzle provides helpful abstractions over sending POST data.

### Sending Form Fields

Sending `application/x-www-form-urlencoded` POST requests requires that you
specify the POST fields as an array in the `form_params` request options.

```php
$response = $client->request('POST', 'http://httpbin.org/post', [
    'form_params' => [
        'field_name' => 'abc',
        'other_field' => '123',
        'nested_field' => [
            'nested' => 'hello'
        ]
    ]
]);
```

### Sending Form Files

You can send files along with a form (`multipart/form-data` POST requests),
using the `multipart` request option. `multipart` accepts an array of part
arrays, where each part array contains the following keys:

- name: (required, string|int) key mapping to the form field name.
- contents: (required, mixed) Provide a string to send the contents of the file
  as a string, provide an fopen resource to stream the contents from a PHP
  stream, provide a `Psr\Http\Message\StreamInterface` to stream the contents
  from a PSR-7 stream, or provide an array to expand nested multipart fields.

```php
use GuzzleHttp\Psr7;

$response = $client->request('POST', 'http://httpbin.org/post', [
    'multipart' => [
        [
            'name'     => 'field_name',
            'contents' => 'abc'
        ],
        [
            'name'     => 'file_name',
            'contents' => Psr7\Utils::tryFopen('/path/to/file', 'r')
        ],
        [
            'name'     => 'other_file',
            'contents' => 'hello',
            'filename' => 'filename.txt',
            'headers'  => [
                'X-Foo' => 'this is an extra header to include'
            ]
        ]
    ]
]);
```

## Related

- [Quick Start](quick-start.md)
- [Request Options](request-options.md)
