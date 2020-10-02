<?php

namespace GuzzleHttp;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Formats log messages using variable substitutions for requests, responses,
 * and other transactional data.
 *
 * The following variable substitutions are supported:
 *
 * - {request}:        Full HTTP request message
 * - {response}:       Full HTTP response message
 * - {ts}:             ISO 8601 date in GMT
 * - {date_iso_8601}   ISO 8601 date in GMT
 * - {date_common_log} Apache common log date using the configured timezone.
 * - {host}:           Host of the request
 * - {method}:         Method of the request
 * - {uri}:            URI of the request
 * - {version}:        Protocol version
 * - {target}:         Request target of the request (path + query + fragment)
 * - {hostname}:       Hostname of the machine that sent the request
 * - {code}:           Status code of the response (if available)
 * - {phrase}:         Reason phrase of the response  (if available)
 * - {error}:          Any error messages (if available)
 * - {req_header_*}:   Replace `*` with the lowercased name of a request header to add to the message
 * - {res_header_*}:   Replace `*` with the lowercased name of a response header to add to the message
 * - {req_headers}:    Request headers
 * - {res_headers}:    Response headers
 * - {req_body}:       Request body
 * - {res_body}:       Response body
 *
 * @final
 */
class MessageFormatter implements MessageFormatterInterface
{
    /**
     * Apache Common Log Format.
     *
     * @link https://httpd.apache.org/docs/2.4/logs.html#common
     *
     * @var string
     */
    public const CLF = "{hostname} {req_header_User-Agent} - [{date_common_log}] \"{method} {target} HTTP/{version}\" {code} {res_header_Content-Length}";
    public const DEBUG = ">>>>>>>>\n{request}\n<<<<<<<<\n{response}\n--------\n{error}";
    public const SHORT = '[{ts}] "{method} {target} HTTP/{version}" {code}';

    /**
     * @var string Template used to format log messages
     */
    private $template;

    /**
     * @param string $template Log message template
     */
    public function __construct(?string $template = self::CLF)
    {
        $this->template = $template ?: self::CLF;
    }

    /**
     * Returns a formatted message string.
     *
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface|null $response Response that was received
     * @param \Throwable|null        $error    Exception that was received
     */
    public function format(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ): string {
        $cache = [];

        /** @var string */
        return \preg_replace_callback(
            '/{\s*([A-Za-z_\-\.0-9]+)\s*}/',
            function (array $matches) use ($request, $response, $error, &$cache) {
                $match = $matches[1];
                if (isset($cache[$match])) {
                    return $cache[$match];
                }

                $result = $this->handleFormatMatch($match, $request, $response, $error);
                $cache[$match] = $result;

                return $result;
            },
            $this->template
        );
    }

    /**
     * Handles a match when formatting based off of a given template.
     *
     * @param string                 $match    Match string
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface|null $response Response that was received
     * @param \Throwable|null        $error    Exception that was received
     * @return string
     */
    protected function handleFormatMatch(
        string $match,
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ) {
        switch ($match) {
            case 'request':
                return $this->formatRequest($request);
            case 'response':
                return $this->formatResponse($response);
            case 'req_headers':
                return $this->formatRequestHeaders($request);
            case 'res_headers':
                return $this->formatResponseHeaders($response);
            case 'req_body':
                return $this->formatRequestBody($request);
            case 'res_body':
                return $this->formatResponseBody($response);
            case 'ts':
            case 'date_iso_8601':
                return $this->formatTimestamp();
            case 'date_common_log':
                return $this->formatDateCommonLog();
            case 'method':
                return $this->formatMethod($request);
            case 'uri':
            case 'url':
                return $this->formatUri($request);
            case 'target':
                return $this->formatTarget($request);
            case 'version':
            case 'req_version':
                return $this->formatRequestVersion($request);
            case 'res_version':
                return $this->formatResponseVersion($response);
            case 'host':
                return $this->formatHost($request);
            case 'hostname':
                return $this->formatHostname();
            case 'code':
                return $this->formatCode($response);
            case 'phrase':
                return $this->formatPhrase($response);
            case 'error':
                return $this->formatError($error);
            default:
                return $this->formatUnexpectedMatch($match, $request, $response);
        }
    }

    /**
     * Formats a request object.
     *
     * @param RequestInterface $request Request that was sent
     * @return string
     */
    protected function formatRequest(RequestInterface $request): string
    {
        return Psr7\Message::toString($request);
    }

    /**
     * Formats a response object.
     *
     * @param ResponseInterface|null $response Response that was received
     * @return string
     */
    protected function formatResponse(?ResponseInterface $response = null): string
    {
        return $response ? Psr7\Message::toString($response) : '';
    }

    /**
     * Formats a request object's headers.
     *
     * @param RequestInterface $request Request that was sent
     * @return string
     */
    protected function formatRequestHeaders(RequestInterface $request): string
    {
        return \trim($request->getMethod()
                . ' ' . $request->getRequestTarget())
            . ' HTTP/' . $request->getProtocolVersion() . "\r\n"
            . $this->headers($request);
    }

    /**
     * Formats a response object's headers.
     *
     * @param ResponseInterface|null $response Response that was received
     * @return string
     */
    protected function formatResponseHeaders(?ResponseInterface $response = null): string
    {
        return $response ?
            \sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ) . "\r\n" . $this->headers($response)
            : 'NULL';
    }

    /**
     * Formats a request body.
     *
     * @param RequestInterface $request Request that was sent
     * @return string
     */
    protected function formatRequestBody(RequestInterface $request): string
    {
        return $request->getBody()->__toString();
    }

    /**
     * Formats a response body.
     *
     * @param ResponseInterface|null $response Response that was received
     * @return string
     */
    protected function formatResponseBody(?ResponseInterface $response = null): string
    {
        if (!$response instanceof ResponseInterface) {
            return 'NULL';
        }

        $body = $response->getBody();

        if (!$body->isSeekable()) {
            return 'RESPONSE_NOT_LOGGEABLE';
        }

        return $response->getBody()->__toString();
    }

    /**
     * Formats a timestamp.
     *
     * @return string
     */
    protected function formatTimestamp(): string
    {
        return \gmdate('c');
    }

    /**
     * Formats a date common log.
     *
     * @return string
     */
    protected function formatDateCommonLog(): string
    {
        return \date('d/M/Y:H:i:s O');
    }

    /**
     * Formats a request method.
     *
     * @param RequestInterface $request Request that was sent
     * @return string
     */
    protected function formatMethod(RequestInterface $request): string
    {
        return $request->getMethod();
    }

    /**
     * Formats a request uri.
     *
     * @param RequestInterface $request Request that was sent
     * @return string
     */
    protected function formatUri(RequestInterface $request): string
    {
        return $request->getUri();
    }

    /**
     * Formats a request's target.
     *
     * @param RequestInterface $request Request that was sent
     * @return string
     */
    protected function formatTarget(RequestInterface $request): string
    {
        return $request->getRequestTarget();
    }

    /**
     * Formats a request's protocol version.
     *
     * @param RequestInterface|null $request Request that was sent
     * @return string
     */
    protected function formatRequestVersion(RequestInterface $request = null): string
    {
        return $request->getProtocolVersion();
    }

    /**
     * Formats a response's protocol version.
     *
     * @param ResponseInterface|null $response Response that was received
     * @return string
     */
    protected function formatResponseVersion(?ResponseInterface $response = null): string
    {
        return $response
            ? $response->getProtocolVersion()
            : 'NULL';
    }

    /**
     * Formats a request's Host header.
     *
     * @param RequestInterface $request Request that was sent
     * @return string
     */
    protected function formatHost(RequestInterface $request): string
    {
        return $request->getHeaderLine('Host');
    }

    /**
     * Formats a hostname.
     *
     * @return string
     */
    protected function formatHostname(): string
    {
        return \gethostname();
    }

    /**
     * Formats a response's status code.
     *
     * @param ResponseInterface|null $response Response that was received
     * @return string
     */
    protected function formatCode(?ResponseInterface $response = null): string
    {
        return $response ? $response->getStatusCode() : 'NULL';
    }

    /**
     * Formats a response's reason phrase.
     *
     * @param ResponseInterface|null $response Response that was received
     * @return string
     */
    protected function formatPhrase(?ResponseInterface $response = null): string
    {
        return $response ? $response->getReasonPhrase() : 'NULL';
    }

    /**
     * Formats an error.
     *
     * @param \Throwable|null $error Exception that was received
     * @return string
     */
    protected function formatError(?\Throwable $error = null): string
    {
        return $error ? $error->getMessage() : 'NULL';
    }

    /**
     * Formats a "default" or unexpected match.
     *
     * @param string                 $match    Match string
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface|null $response Response that was received
     * @return string
     */
    protected function formatUnexpectedMatch(
        string $match,
        RequestInterface $request,
        ?ResponseInterface $response = null): string
    {
        // handle prefixed dynamic headers
        if (\strpos($match, 'req_header_') === 0) {
            return $request->getHeaderLine(\substr($match, 11));
        } elseif (\strpos($match, 'res_header_') === 0) {
            return $response
                ? $response->getHeaderLine(\substr($match, 11))
                : 'NULL';
        }

        return '';
    }

    /**
     * Get headers from message as string
     */
    protected function headers(MessageInterface $message): string
    {
        $result = '';
        foreach ($message->getHeaders() as $name => $values) {
            $result .= $name . ': ' . \implode(', ', $values) . "\r\n";
        }

        return \trim($result);
    }
}
