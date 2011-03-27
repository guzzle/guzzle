<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Curl;

/**
 * Class that holds curl constants, and can return curl option names or curl
 * option values based on an integer.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CurlConstants
{
    /**
     * @var cURL options array
     */
    protected static $options = array(
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
        'CURLOPT_CONNECTTIMEOUT_MS' => CURLOPT_CONNECTTIMEOUT_MS,
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
        'CURLOPT_PROTOCOLS' => CURLOPT_PROTOCOLS,
        'CURLOPT_PROXYAUTH' => CURLOPT_PROXYAUTH,
        'CURLOPT_PROXYPORT' => CURLOPT_PROXYPORT,
        'CURLOPT_PROXYTYPE' => CURLOPT_PROXYTYPE,
        'CURLOPT_REDIR_PROTOCOLS' => CURLOPT_REDIR_PROTOCOLS,
        'CURLOPT_RESUME_FROM' => CURLOPT_RESUME_FROM,
        'CURLOPT_SSL_VERIFYHOST' => CURLOPT_SSL_VERIFYHOST,
        'CURLOPT_SSLVERSION' => CURLOPT_SSLVERSION,
        'CURLOPT_TIMECONDITION' => CURLOPT_TIMECONDITION,
        'CURLOPT_TIMEOUT' => CURLOPT_TIMEOUT,
        'CURLOPT_TIMEOUT_MS' => CURLOPT_TIMEOUT_MS,
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
    protected static $values = array (
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

    /**
     * Get the string name of a curl option
     *
     * @param int $optionInt Option value
     *
     * @return string|false
     */
    public static function getOptionName($optionInt)
    {
        return array_search($optionInt, self::$options);
    }

    /**
     * Get the string value of a curl value
     *
     * @param int $valueInt Curl value to retrieve
     *
     * @return string|false
     */
    public static function getValueName($valueInt)
    {
        return array_search($valueInt, self::$values);
    }

    /**
     * Get the integer value of a curl option by name
     *
     * @param int $optionName Name of the option
     *
     * @return int|null
     */
    public static function getOptionInt($optionName)
    {
        $optionName = strtoupper($optionName);

        return isset(self::$options[$optionName]) ? self::$options[$optionName] : false;
    }

    /**
     * Get the integer value of a curl value by name
     *
     * @param int $valueName Name of the value
     *
     * @return int|null
     */
    public static function getValueInt($valueName)
    {
        $valueName = strtoupper($valueName);

        return isset(self::$values[$valueName]) ? self::$values[$valueName] : false;
    }

    /**
     * Get all of the curl options as an associative array where the string
     * name is the key and the integer value is the value.
     *
     * @return array
     */
    public static function getOptions()
    {
        return self::$options;
    }

    /**
     * Get all of the curl values as an associative array where the string
     * name is the key and the integer value is the value.
     *
     * @return array
     */
    public static function getValues()
    {
        return self::$values;
    }
}