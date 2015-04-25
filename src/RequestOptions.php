<?php
namespace GuzzleHttp;

/**
 * This class contains a list of built-in Guzzle request options.
 *
 * The documentation for each option can be found at http://guzzlephp.org/.
 *
 * @link http://docs.guzzlephp.org/en/v6/request-options.html
 */
final class RequestOptions
{
    const ALLOW_REDIRECTS = 'allow_redirects';
    const AUTH = 'auth';
    const BODY = 'body';
    const CERT = 'cert';
    const COOKIES = 'cookies';
    const CONNECT_TIMEOUT = 'connect_timeout';
    const DEBUG = 'debug';
    const DECODE_CONTENT = 'decode_content';
    const DELAY = 'delay';
    const EXPECT = 'expect';
    const HEADERS = 'headers';
    const HTTP_ERRORS = 'http_errors';
    const JSON = 'json';
    const PROXY = 'proxy';
    const QUERY = 'query';
    const SINK = 'sink';
    const SSL_KEY = 'ssl_key';
    const STREAM = 'stream';
    const VERIFY = 'verify';
    const TIMEOUT = 'timeout';
    const VERSION = 'version';
}
