<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Guzzle;
use Guzzle\Http\Curl\CurlConstants;
use Guzzle\Http\Curl\CurlFactory;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\QueryString;
use Guzzle\Http\Url;
use Guzzle\Tests\Common\Mock\MockObserver;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 * @covers Guzzle\Http\Curl\CurlFactory
 */
class CurlFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var cURL options array
     */
    public static $options = array(
        'CURLOPT_AUTOREFERER' => CURLOPT_AUTOREFERER,
        'CURLOPT_BINARYTRANSFER' => CURLOPT_BINARYTRANSFER,
        'CURLOPT_COOKIESESSION' => CURLOPT_COOKIESESSION,
        'CURLOPT_CRLF' => CURLOPT_CRLF,
        'CURLOPT_DNS_USE_GLOBAL_CACHE' => CURLOPT_DNS_USE_GLOBAL_CACHE,
        'CURLOPT_FAILONERROR' => CURLOPT_FAILONERROR,
        'CURLOPT_FILETIME' => CURLOPT_FILETIME,
        'CURLOPT_FOLLOWLOCATION' => CURLOPT_FOLLOWLOCATION,
        'CURLOPT_FORBID_REUSE' => CURLOPT_FORBID_REUSE,
        'CURLOPT_FRESH_CONNECT' => CURLOPT_FRESH_CONNECT,
        'CURLOPT_FTP_USE_EPRT' => CURLOPT_FTP_USE_EPRT,
        'CURLOPT_FTP_USE_EPSV' => CURLOPT_FTP_USE_EPSV,
        'CURLOPT_FTPAPPEND' => CURLOPT_FTPAPPEND,
        'CURLOPT_FTPLISTONLY' => CURLOPT_FTPLISTONLY,
        'CURLOPT_HEADER' => CURLOPT_HEADER,
        'CURLINFO_HEADER_OUT' => CURLINFO_HEADER_OUT,
        'CURLOPT_HTTPGET' => CURLOPT_HTTPGET,
        'CURLOPT_HTTPPROXYTUNNEL' => CURLOPT_HTTPPROXYTUNNEL,
        'CURLOPT_NETRC' => CURLOPT_NETRC,
        'CURLOPT_NOBODY' => CURLOPT_NOBODY,
        'CURLOPT_NOPROGRESS' => CURLOPT_NOPROGRESS,
        'CURLOPT_NOSIGNAL' => CURLOPT_NOSIGNAL,
        'CURLOPT_POST' => CURLOPT_POST,
        'CURLOPT_PUT' => CURLOPT_PUT,
        'CURLOPT_RETURNTRANSFER' => CURLOPT_RETURNTRANSFER,
        'CURLOPT_SSL_VERIFYPEER' => CURLOPT_SSL_VERIFYPEER,
        'CURLOPT_TRANSFERTEXT' => CURLOPT_TRANSFERTEXT,
        'CURLOPT_UNRESTRICTED_AUTH' => CURLOPT_UNRESTRICTED_AUTH,
        'CURLOPT_UPLOAD' => CURLOPT_UPLOAD,
        'CURLOPT_VERBOSE' => CURLOPT_VERBOSE,
        'CURLOPT_BUFFERSIZE' => CURLOPT_BUFFERSIZE,
        'CURLOPT_CLOSEPOLICY' => CURLOPT_CLOSEPOLICY,
        'CURLOPT_CONNECTTIMEOUT' => CURLOPT_CONNECTTIMEOUT,
        // 'CURLOPT_CONNECTTIMEOUT_MS' => CURLOPT_CONNECTTIMEOUT_MS,
        'CURLOPT_DNS_CACHE_TIMEOUT' => CURLOPT_DNS_CACHE_TIMEOUT,
        'CURLOPT_FTPSSLAUTH' => CURLOPT_FTPSSLAUTH,
        'CURLOPT_HTTP_VERSION' => CURLOPT_HTTP_VERSION,
        'CURLOPT_HTTPAUTH' => CURLOPT_HTTPAUTH,
        'CURLAUTH_ANY' => CURLAUTH_ANY,
        'CURLAUTH_ANYSAFE' => CURLAUTH_ANYSAFE,
        'CURLOPT_INFILESIZE' => CURLOPT_INFILESIZE,
        'CURLOPT_LOW_SPEED_LIMIT' => CURLOPT_LOW_SPEED_LIMIT,
        'CURLOPT_LOW_SPEED_TIME' => CURLOPT_LOW_SPEED_TIME,
        'CURLOPT_MAXCONNECTS' => CURLOPT_MAXCONNECTS,
        'CURLOPT_MAXREDIRS' => CURLOPT_MAXREDIRS,
        'CURLOPT_PORT' => CURLOPT_PORT,
        // 'CURLOPT_PROTOCOLS' => CURLOPT_PROTOCOLS,
        'CURLOPT_PROXYAUTH' => CURLOPT_PROXYAUTH,
        'CURLOPT_PROXYPORT' => CURLOPT_PROXYPORT,
        'CURLOPT_PROXYTYPE' => CURLOPT_PROXYTYPE,
        // 'CURLOPT_REDIR_PROTOCOLS' => CURLOPT_REDIR_PROTOCOLS,
        'CURLOPT_RESUME_FROM' => CURLOPT_RESUME_FROM,
        'CURLOPT_SSL_VERIFYHOST' => CURLOPT_SSL_VERIFYHOST,
        'CURLOPT_SSLVERSION' => CURLOPT_SSLVERSION,
        'CURLOPT_TIMECONDITION' => CURLOPT_TIMECONDITION,
        'CURLOPT_TIMEOUT' => CURLOPT_TIMEOUT,
        // 'CURLOPT_TIMEOUT_MS' => CURLOPT_TIMEOUT_MS,
        'CURLOPT_TIMEVALUE' => CURLOPT_TIMEVALUE,
        'CURLOPT_CAINFO' => CURLOPT_CAINFO,
        'CURLOPT_CAPATH' => CURLOPT_CAPATH,
        'CURLOPT_COOKIE' => CURLOPT_COOKIE,
        'CURLOPT_COOKIEFILE' => CURLOPT_COOKIEFILE,
        'CURLOPT_COOKIEJAR' => CURLOPT_COOKIEJAR,
        'CURLOPT_CUSTOMREQUEST' => CURLOPT_CUSTOMREQUEST,
        'CURLOPT_EGDSOCKET' => CURLOPT_EGDSOCKET,
        'CURLOPT_ENCODING' => CURLOPT_ENCODING,
        'CURLOPT_FTPPORT' => CURLOPT_FTPPORT,
        'CURLOPT_INTERFACE' => CURLOPT_INTERFACE,
        'CURLOPT_KRB4LEVEL' => CURLOPT_KRB4LEVEL,
        'CURLOPT_POSTFIELDS' => CURLOPT_POSTFIELDS,
        'CURLOPT_PROXY' => CURLOPT_PROXY,
        'CURLOPT_PROXYUSERPWD' => CURLOPT_PROXYUSERPWD,
        'CURLOPT_RANDOM_FILE' => CURLOPT_RANDOM_FILE,
        'CURLOPT_RANGE' => CURLOPT_RANGE,
        'CURLOPT_REFERER' => CURLOPT_REFERER,
        'CURLOPT_SSL_CIPHER_LIST' => CURLOPT_SSL_CIPHER_LIST,
        'CURLOPT_SSLCERT' => CURLOPT_SSLCERT,
        'CURLOPT_SSLCERTPASSWD' => CURLOPT_SSLCERTPASSWD,
        'CURLOPT_SSLCERTTYPE' => CURLOPT_SSLCERTTYPE,
        'CURLOPT_SSLENGINE' => CURLOPT_SSLENGINE,
        'CURLOPT_SSLENGINE_DEFAULT' => CURLOPT_SSLENGINE_DEFAULT,
        'CURLOPT_SSLKEY' => CURLOPT_SSLKEY,
        'CURLOPT_SSLKEYPASSWD' => CURLOPT_SSLKEYPASSWD,
        'CURLOPT_SSLKEYTYPE' => CURLOPT_SSLKEYTYPE,
        'CURLOPT_URL' => CURLOPT_URL,
        'CURLOPT_USERAGENT' => CURLOPT_USERAGENT,
        'CURLOPT_USERPWD' => CURLOPT_USERPWD,
        'CURLOPT_HTTP200ALIASES' => CURLOPT_HTTP200ALIASES,
        'CURLOPT_HTTPHEADER' => CURLOPT_HTTPHEADER,
        'CURLOPT_POSTQUOTE' => CURLOPT_POSTQUOTE,
        'CURLOPT_QUOTE' => CURLOPT_QUOTE,
        'CURLOPT_FILE' => CURLOPT_FILE,
        'CURLOPT_INFILE' => CURLOPT_INFILE,
        'CURLOPT_STDERR' => CURLOPT_STDERR,
        'CURLOPT_WRITEHEADER' => CURLOPT_WRITEHEADER,
        'CURLOPT_HEADERFUNCTION' => CURLOPT_HEADERFUNCTION,
        'CURLOPT_PROGRESSFUNCTION' => CURLOPT_PROGRESSFUNCTION,
        'CURLOPT_READFUNCTION' => CURLOPT_READFUNCTION,
        'CURLOPT_WRITEFUNCTION' => CURLOPT_WRITEFUNCTION
    );

    /**
     * @var array cURL option values
     */
    public static $values = array (
        'CURLAUTH_BASIC' => CURLAUTH_BASIC,
        'CURLAUTH_DIGEST' => CURLAUTH_DIGEST,
        'CURLAUTH_GSSNEGOTIATE' => CURLAUTH_GSSNEGOTIATE,
        'CURLAUTH_NTLM' => CURLAUTH_NTLM,
        'CURLCLOSEPOLICY_CALLBACK' => CURLCLOSEPOLICY_CALLBACK,
        'CURLCLOSEPOLICY_LEAST_RECENTLY_USED' => CURLCLOSEPOLICY_LEAST_RECENTLY_USED,
        'CURLCLOSEPOLICY_LEAST_TRAFFIC' => CURLCLOSEPOLICY_LEAST_TRAFFIC,
        'CURLCLOSEPOLICY_OLDEST' => CURLCLOSEPOLICY_OLDEST,
        'CURLCLOSEPOLICY_SLOWEST' => CURLCLOSEPOLICY_SLOWEST,
        'CURLFTPAUTH_DEFAULT' => CURLFTPAUTH_DEFAULT,
        'CURLFTPAUTH_SSL' => CURLFTPAUTH_SSL,
        'CURLFTPAUTH_TLS' => CURLFTPAUTH_TLS,
        'CURLFTPSSL_ALL' => CURLFTPSSL_ALL,
        'CURLFTPSSL_CONTROL' => CURLFTPSSL_CONTROL,
        'CURLFTPSSL_NONE' => CURLFTPSSL_NONE,
        'CURLFTPSSL_TRY' => CURLFTPSSL_TRY,
        'CURLINFO_FILETIME' => CURLINFO_FILETIME,
        'CURLINFO_HEADER_OUT' => CURLINFO_HEADER_OUT,
        'CURLOPT_FTP_CREATE_MISSING_DIRS' => CURLOPT_FTP_CREATE_MISSING_DIRS,
        'CURLOPT_FTP_SSL' => CURLOPT_FTP_SSL,
        'CURLOPT_PRIVATE' => CURLOPT_PRIVATE,
        'CURLOPT_READDATA' => CURLOPT_READDATA,
        'CURLOPT_TCP_NODELAY' => CURLOPT_TCP_NODELAY,
        'CURLPROXY_HTTP' => CURLPROXY_HTTP,
        'CURLPROXY_SOCKS5' => CURLPROXY_SOCKS5,
        'CURL_HTTP_VERSION_1_0' => CURL_HTTP_VERSION_1_0,
        'CURL_HTTP_VERSION_1_1' => CURL_HTTP_VERSION_1_1,
        'CURL_HTTP_VERSION_NONE' => CURL_HTTP_VERSION_NONE,
        'CURL_NETRC_IGNORED' => CURL_NETRC_IGNORED,
        'CURL_NETRC_OPTIONAL' => CURL_NETRC_OPTIONAL,
        'CURL_NETRC_REQUIRED' => CURL_NETRC_REQUIRED,
        'CURL_TIMECOND_IFMODSINCE' => CURL_TIMECOND_IFMODSINCE,
        'CURL_TIMECOND_IFUNMODSINCE' => CURL_TIMECOND_IFUNMODSINCE,
        'CURL_TIMECOND_LASTMOD' => CURL_TIMECOND_LASTMOD
    );

    public static $dontConvert = array(
        'CURLOPT_MAXREDIRS',
        'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_FILETIME',
        'CURLOPT_SSL_VERIFYPEER',
        'CURLOPT_SSL_VERIFYHOST',
        'CURLOPT_RETURNTRANSFER',
        'CURLOPT_HTTPHEADER',
        'CURLOPT_HEADER',
        'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS',
        'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_USERAGENT',
        'CURLOPT_NOPROGRESS',
        'CURLOPT_BUFFERSIZE',
        'CURLOPT_PORT'
    );

    /**
     * Convert cURL option and value integers into a readable array
     *
     * @param array $options
     *
     * @return array
     */
    public static function getReadableCurlOptions(array $options)
    {
        $readable = array();

        foreach ($options as $key => $value) {

            $readableKey = $key;
            $readableValue = $value;

            // Convert the key
            foreach (self::$options as $ok => $ov) {
                if ($ov === $key) {
                    $readableKey = $ok;
                    break;
                }
            }

            if (!in_array($readableKey, self::$dontConvert)) {
                foreach (self::$values as $k => $v) {
                    if ($value == 1 && $readableKey != 'CURLOPT_HTTPAUTH') {
                        $readableValue = true;
                    } else if ($v && $v === $value) {
                        $readableValue = $k;
                        break;
                    } else if (is_array($value) || $value instanceof \Closure) {
                        $readableValue = 'callback';
                    }
                }
            }

            $readable[$readableKey] = $readableValue;
        }

        return $readable;
    }

    public function dataProvider()
    {
        $postBody = new QueryString(array(
            'file' => '@' . __DIR__ . '/../../../../../phpunit.xml'
        ));

        $qs = new QueryString(array(
            'x' => 'y',
            'z' => 'a'
        ));

        $userAgent = Guzzle::getDefaultUserAgent();
        $auth = base64_encode('michael:123');

        return array(
            array('GET', 'http://www.google.com/', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: www.google.com'),
            )),
            // Test that custom request methods can be used
            array('TRACE', 'http://www.google.com/', null, null, array(
                CURLOPT_CUSTOMREQUEST => 'TRACE'
            )),
            array('GET', 'http://127.0.0.1:8080', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_PORT => 8080,
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: 127.0.0.1:8080'),
            )),
            array('HEAD', 'http://www.google.com/', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: www.google.com'),
                CURLOPT_CUSTOMREQUEST => 'HEAD',
                CURLOPT_NOBODY => 1
            )),
            array('GET', 'https://michael:123@www.guzzle-project.com/index.html?q=2', null, null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('User-Agent: ' . $userAgent, 'Host: www.guzzle-project.com', 'Authorization: Basic ' . $auth),
                CURLOPT_PORT => 443
            )),
            array('GET', 'http://www.guzzle-project.com:8080/', array(
                    'X-Test-Data' => 'Guzzle'
                ), null, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array('X-Test-Data: Guzzle', 'User-Agent: ' . $userAgent, 'Host: www.guzzle-project.com:8080'),
                CURLOPT_PORT => 8080
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, $qs, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POSTFIELDS => 'x=y&z=a',
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('PUT', 'http://www.guzzle-project.com/put.php', null, EntityBody::factory(fopen(__DIR__ . '/../../../../../phpunit.xml', 'r+')), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_READFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_INFILESIZE => filesize(__DIR__ . '/../../../../../phpunit.xml'),
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Expect: 100-Continue',
                    'Content-Type: '
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, array(
                'a' => '2'
            ), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => 'a=2',
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, array(
                'x' => 'y'
            ), array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => 'x=y',
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Content-Type: application/x-www-form-urlencoded'
                )
            )),
            array('POST', 'http://www.guzzle-project.com/post.php', null, $postBody, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_WRITEFUNCTION => 'callback',
                CURLOPT_HEADERFUNCTION => 'callback',
                CURLOPT_PROGRESSFUNCTION => 'callback',
                CURLOPT_NOPROGRESS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => array(
                    'file' => '@' . __DIR__ . '/../../../../../phpunit.xml'
                ),
                CURLOPT_HTTPHEADER => array (
                    'User-Agent: ' . $userAgent,
                    'Host: www.guzzle-project.com',
                    'Content-Type: multipart/form-data'
                )
            )),
        );
    }

    public function setUp()
    {
        CurlFactory::getInstance()->releaseAllHandles(true);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory::releaseAllHandles
     */
    public function testReleasesAllHandles()
    {
        $f = CurlFactory::getInstance();
        $f->releaseAllHandles(true);
        $request = RequestFactory::head($this->getServer()->getUrl());
        $request->getCurlHandle();
        $this->assertTrue($f->getConnectionsPerHost(true, '127.0.0.1:8124') > 0);
        $f->releaseAllHandles(true);
        $this->assertEquals(0, $f->getConnectionsPerHost(false, '127.0.0.1:8124'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     * @dataProvider dataProvider
     */
    public function testFactoryCreatesCurlResourceBasedOnRequest($method, $url, $headers, $body, $options)
    {
        $request = RequestFactory::create($method, $url, $headers, $body);
        $handle = $request->getCurlHandle();
        $this->assertInstanceOf('Guzzle\\Http\\Curl\\CurlHandle', $handle);
        $o = $request->getCurlOptions()->getAll();

        foreach ($options as $key => $value) {
            $this->assertArrayHasKey($key, $o);
            if ($key != CURLOPT_HTTPHEADER && $key != CURLOPT_POSTFIELDS && (is_array($o[$key])) || $o[$key] instanceof \Closure) {
                $this->assertEquals('callback', $value);
            } else {
                $this->assertTrue($value == $o[$key]);
            }
        }

        $request->releaseCurlHandle();
        unset($request);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     */
    public function testFactoryUsesSpecifiedProtocol()
    {
        $request = RequestFactory::get('http://www.guzzle-project.com/');
        $request->setProtocolVersion('1.1');
        $handle = CurlFactory::getInstance()->getHandle($request);
        $this->assertEquals(CURL_HTTP_VERSION_1_1, $request->getCurlOptions()->get(CURLOPT_HTTP_VERSION));
        $request->releaseCurlHandle();
        unset($request);
    }

    /**
     * Tests that a handle can be used for auth requests and non-auth requests
     * without mucking up sending credentials when it shouldn't
     *
     * @covers Guzzle\Http\Curl\CurlFactory
     */
    public function testFactoryCanReuseAuthHandles()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $host = Url::factory($this->getServer()->getUrl());
        $host = $host->getHost() . ':' . $host->getPort();

        $request = RequestFactory::get($this->getServer()->getUrl());
        $h1 = $request->getCurlHandle();
        $request->send();
        $this->assertFalse($this->compareHttpHeaders(array(
            'Accept' => '*/*',
            'Accept-Encoding' => 'deflate, gzip',
            'User-Agent' => Guzzle::getDefaultUserAgent(),
            'Host' => $host
        ), $request->getHeaders()->getAll()));

        $request->setState('new');
        $request->setAuth('michael', 'test');
        $h2 = $request->getCurlHandle();
        $request->send();
        $this->assertFalse($this->compareHttpHeaders(array(
             'Accept-Encoding' => 'deflate, gzip',
             'Accept' =>  '*/*',
             'User-Agent' => Guzzle::getDefaultUserAgent(),
             'Host' => $host,
             'Authorization' => 'Basic bWljaGFlbDp0ZXN0'
        ), $request->getHeaders()->getAll()));

        $request->setState('new');
        $request->setAuth(false);
        $h3 = $request->getCurlHandle();
        $request->send();
        $this->assertFalse($this->compareHttpHeaders(array(
            'Accept-Encoding' => 'deflate, gzip',
            'Accept' => '*/*',
            'User-Agent' => Guzzle::getDefaultUserAgent(),
            'Host' => $host
        ), $request->getHeaders()->getAll()));

        $this->assertSame($h1, $h2);
        $this->assertSame($h1, $h3);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     */
    public function testUploadsDataUsingCurlAndCanReuseHandleAfterUpload()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi", // PUT response
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n",   // HEAD response
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nhi"  // POST response
        ));

        $host = Url::factory($this->getServer()->getUrl());
        $host = $host->getHost() . ':' . $host->getPort();

        $o = new MockObserver();
        $request = RequestFactory::put($this->getServer()->getUrl());
        $request->setBody(EntityBody::factory('test'));
        $request->getEventManager()->attach($o);
        $h1 = $request->getCurlHandle();
        $request->send();

        // Make sure that the events were dispatched
        $this->assertArrayHasKey('curl.callback.read', $o->logByEvent);
        $this->assertArrayHasKey('curl.callback.write', $o->logByEvent);
        $this->assertArrayHasKey('curl.callback.progress', $o->logByEvent);

        // Make sure that the data was sent through the event
        $this->assertEquals('test', $o->logByEvent['curl.callback.read']);
        $this->assertEquals('hi', $o->logByEvent['curl.callback.write']);

        // Ensure that the request was received exactly as intended
        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals((string) $request, (string) $r[0]);

        // Create a new request and try to reuse the connection
        $request = RequestFactory::head($this->getServer()->getUrl());
        $this->assertSame($h1, $request->getCurlHandle());
        $request->send();

        // Make sure that the request was sent correctly
        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals((string) $request, (string) $r[1]);

        // Create a new request using the same connection and POST
        $request = RequestFactory::post($this->getServer()->getUrl());
        $request->addPostFields(array(
            'a' => 'b',
            'c' => 'ay! ~This is a test, isn\'t it?'
        ));
        $this->assertSame($h1, $request->getCurlHandle());
        $request->send();

        // Make sure that the request was sent correctly
        $r = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals((string) $request, (string) $r[2]);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory
     * @covers Guzzle\Http\Curl\CurlFactory::getConnectionsPerHost
     * @depends testReleasesAllHandles
     */
    public function testClosesHandlesWhenHandlesAreReleasedAndNeedToBeClosed()
    {
        $f = CurlFactory::getInstance();
        $baseline = $f->getConnectionsPerHost(true, '127.0.0.1:8124');
        $request1 = RequestFactory::get($this->getServer()->getUrl());
        $request1->getCurlHandle();
        $request2 = RequestFactory::get($this->getServer()->getUrl());
        $request2->getCurlHandle();

        // Make sure tha allocated count went up
        $current = $f->getConnectionsPerHost(true, '127.0.0.1:8124');
        $this->assertEquals($baseline + 2, $current);

        // Release the handles so they are unallocated and cleaned back to 2
        $request1->releaseCurlHandle();
        $request2->releaseCurlHandle();

        $current = $f->getConnectionsPerHost(true, '127.0.0.1:8124');
        $this->assertEquals($baseline, $current);

        $current = $f->getConnectionsPerHost(false, '127.0.0.1:8124');
        $this->assertEquals(2, $current);

        $this->assertSame($f, $f->setMaxIdleForHost('127.0.0.1:8124', 1));
        $this->assertSame($f, $f->clean());
        $current = $f->getConnectionsPerHost(null, '127.0.0.1:8124');
        $this->assertEquals(1, $current);

        // Purge all unalloacted connections
        $f->clean(true);
        $this->assertEquals(array(), $f->getConnectionsPerHost(false));

        $request = RequestFactory::head($this->getServer()->getUrl());
        $handle1 = $request->getCurlHandle();
        $this->assertEquals(1, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));
        $f->releaseHandle($handle1);
        $this->assertEquals(0, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));
        $this->assertEquals(1, $f->getConnectionsPerHost(false, '127.0.0.1:8124'));
        // Relase and force close
        $f->releaseHandle($handle1, true);

        // Make sure that the handle was closed
        $this->assertEquals(0, $f->getConnectionsPerHost(null, '127.0.0.1:8124'));
        $request = RequestFactory::head($this->getServer()->getUrl());
        $handle2 = $request->getCurlHandle();
        $this->assertNotSame($handle1, $handle2);
        $this->assertEquals(1, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));

        curl_close($handle2->getHandle());
        $f->releaseHandle($handle2);
        $this->assertEquals(0, $f->getConnectionsPerHost(null, '127.0.0.1:8124'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory::setMaxIdleTime
     * @covers Guzzle\Http\Curl\CurlFactory::clean
     * @covers Guzzle\Http\Curl\CurlFactory::getConnectionsPerHost
     * @depends testReleasesAllHandles
     */
    public function testPurgesConnectionsThatAreTooStaleBasedOnMaxIdleTime()
    {
        $f = CurlFactory::getInstance();
        $this->assertSame($f, $f->setMaxIdleTime(0));
        $request = RequestFactory::head($this->getServer()->getUrl());
        $request->getCurlHandle();
        $this->assertEquals(1, $f->getConnectionsPerHost(true, '127.0.0.1:8124'));
        $this->assertEquals(0, $f->getConnectionsPerHost(false, '127.0.0.1:8124'));

        // By releasing the handle, the factory should clean up the handle
        // because of the max idle time
        $request->releaseCurlHandle();
        $this->assertEquals(0, $f->getConnectionsPerHost(false, '127.0.0.1:8124'));

        // Set the default max idle time
        $f->setMaxIdleTime(-1);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory::setMaxConnectionReusesForHost
     * @covers Guzzle\Http\Curl\CurlFactory::clean
     * @covers Guzzle\Http\Curl\CurlFactory::createHandle
     */
    public function testClosesConnectionsThatHaveExceededMaxConnectionReuse()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
           "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ));

        $f = CurlFactory::getInstance();
        $f->releaseAllHandles(true);
        $f->setMaxConnectionReusesForHost('127.0.0.1:8124', 1);
        $request = RequestFactory::get($this->getServer()->getUrl());
        $h = $request->getCurlHandle();
        $curlHandle = $h->getHandle();
        $this->assertEquals(0, $h->getUseCount());

        $request->send();
        $this->assertEquals(1, $h->getUseCount());
        $this->assertSame($curlHandle, $h->getHandle());
        $this->assertEquals(1, $f->getConnectionsPerHost(null, '127.0.0.1:8124'));

        // Send the request again
        $request->send();
        // The connection has now been released
        $this->assertEquals(0, $f->getConnectionsPerHost(null, '127.0.0.1:8124'));
        $this->assertEquals(0, $h->getUseCount());
        $this->assertNotSame($curlHandle, $h->getHandle());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlFactory::getConnectionsPerHost
     */
    public function testSkipsHostsThatDoNotMatch()
    {
        $f = CurlFactory::getInstance();
        $f->releaseAllHandles(true);

        $request1 = RequestFactory::get('http://www.yahoo.com/');
        $request1->getCurlHandle();
        $request2 = RequestFactory::get('http://www.google.com/');
        $request2->getCurlHandle();

        $this->assertEquals(1, $f->getConnectionsPerHost(null, 'www.yahoo.com:80'));
        $this->assertEquals(1, $f->getConnectionsPerHost(null, 'www.google.com:80'));
        $this->assertEquals(0, $f->getConnectionsPerHost(null, 'foo.com:80'));
        $f->releaseAllHandles(true);
    }
}