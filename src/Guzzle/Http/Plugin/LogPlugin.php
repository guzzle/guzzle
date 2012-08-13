<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Common\Log\LogAdapterInterface;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin class that will add request and response logging to an HTTP request.
 *
 * The log plugin can log contextual information about an HTTP transaction,
 * request and response headers, request and response bodies, and cURL debug
 * information.  These different logging levels can be customized using bitwise
 * operators on LOG_CONTEXT (transaction information), LOG_HEADERS (request and
 * response headers), LOG_BODY (request and response entity bodies), and
 * LOG_DEBUG (cURL info).
 *
 * You will only receive valuable logging information from requests sent using
 * cURL.  Requests with mock responses will not provide valuable information
 * with the log plugin.
 *
 * Be careful when logging entity bodies; before they can
 * be logged, the entire request and response entity bodies must be loaded into
 * memory.
 */
class LogPlugin implements EventSubscriberInterface
{
    // Bitwise log settings
    const LOG_CONTEXT = 1;
    const LOG_HEADERS = 2;
    const LOG_BODY = 4;
    const LOG_DEBUG = 8;
    // Log context, headers, debug, and entity bodies
    const LOG_VERBOSE = 15;

    /**
     * @var LogAdapterInterface Adapter responsible for writing log data
     */
    private $logAdapter;

    /**
     * @var int Bitwise log settings
     */
    private $settings = false;

    /**
     * @var string Cached copy of the hostname
     */
    private $hostname;

    /**
     * Construct a new LogPlugin
     *
     * @param LogAdapterInterface $logAdapter Adapter object used to log message
     * @param int                 $settings   Bitwise settings to use for logging
     */
    public function __construct(LogAdapterInterface $logAdapter, $settings = self::LOG_CONTEXT)
    {
        $this->logAdapter = $logAdapter;
        $this->hostname = gethostname();
        $this->setSettings($settings);
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'curl.callback.write' => array('onCurlWrite', 255),
            'curl.callback.read'  => array('onCurlRead', 255),
            'request.before_send' => array('onRequestBeforeSend', 255),
            'request.complete'    => array('onRequestComplete', 255)
        );
    }

    /**
     * Change the log settings of the plugin
     *
     * @param int $settings Bitwise settings to control what's logged
     *
     * @return LogPlugin
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * Get the log adapter object
     *
     * @return LogAdapterInterface
     */
    public function getLogAdapter()
    {
        return $this->logAdapter;
    }

    /**
     * Event triggered when curl data is read from a request
     *
     * @param Event $event
     */
    public function onCurlRead(Event $event)
    {
        // Stream the request body to the log if the body is not repeatable
        $request = $event['request'];
        if ($request->getParams()->get('request_wire')) {
            $request->getParams()->get('request_wire')->write($event['read']);
        }
    }

    /**
     * Event triggered when curl data is written to a response
     *
     * @param Event $event
     */
    public function onCurlWrite(Event $event)
    {
        // Stream the response body to the log if the body is not repeatable
        $request = $event['request'];
        if ($request->getParams()->get('response_wire')) {
            $request->getParams()->get('response_wire')->write($event['write']);
        }
    }

    /**
     * Called before a request is sent
     *
     * @param Event $event
     */
    public function onRequestBeforeSend(Event $event)
    {
        $request = $event['request'];
        // Ensure that curl IO events are emitted
        $request->getParams()->set('curl.emit_io', true);
        $request->getCurlOptions()->set('debug', true);
        // We need to make special handling for content wiring and
        // non-repeatable streams.
        if ($this->settings & self::LOG_BODY) {
            if ($request instanceof EntityEnclosingRequestInterface) {
                if ($request->getBody() && (!$request->getBody()->isSeekable() || !$request->getBody()->isReadable())) {
                    // The body of the request cannot be recalled so
                    // logging the content of the request will need to
                    // be streamed using updates
                    $request->getParams()->set('request_wire', EntityBody::factory());
                }
            }
            if (!$request->isResponseBodyRepeatable()) {
                // The body of the response cannot be recalled so
                // logging the content of the response will need to
                // be streamed using updates
                $request->getParams()->set('response_wire', EntityBody::factory());
            }
        }
    }

    /**
     * Triggers the actual log write when a request completes
     *
     * @param Event $event
     */
    public function onRequestComplete(Event $event)
    {
        $this->log($event['request'], $event['response']);
    }

    /**
     * Log a message based on a request and response
     *
     * @param RequestInterface $request  Request to log
     * @param Response         $response Response to log
     */
    private function log(RequestInterface $request, Response $response = null)
    {
        $message = '';

        if ($this->settings & self::LOG_CONTEXT) {
            // Log common contextual information
            $message = $request->getHost() . ' - "' .  $request->getMethod()
                . ' ' . $request->getResource() . ' '
                . strtoupper($request->getScheme()) . '/'
                . $request->getProtocolVersion() . '"';

            // If a response is set, then log additional contextual information
            if ($response) {
                $message .= sprintf(' - %s %s - %s %s %s',
                    $response->getStatusCode(),
                    $response->getContentLength() ?: 0,
                    $response->getInfo('total_time'),
                    $response->getInfo('speed_upload'),
                    $response->getInfo('speed_download')
                );
            }
        }

        // Check if we are logging anything that will come from cURL
        if ($request->getParams()->get('curl_handle') && ($this->settings & self::LOG_DEBUG || $this->settings & self::LOG_HEADERS || $this->settings & self::LOG_BODY)) {

            // If context logging too, then add a new line for cleaner messages
            if ($this->settings & self::LOG_CONTEXT) {
                $message .= "\n";
            }

            // Filter cURL's verbose output based on config settings
            $message .= $this->parseCurlLog($request);

            // Log the response body if the response is available
            if ($this->settings & self::LOG_BODY && $response) {
                if ($request->getParams()->get('response_wire')) {
                    $message .= (string) $request->getParams()->get('response_wire');
                } else {
                    $message .= $response->getBody(true);
                }
            }
        }

        // Send the log message to the adapter, adding a category and host
        $priority = $response && !$response->isSuccessful() ? LOG_ERR : LOG_DEBUG;
        $this->logAdapter->log(trim($message), $priority, array(
            'category'  => 'guzzle.request',
            'host'      => $this->hostname,
            'request'   => $request,
            'response'  => $response
        ));
    }

    /**
     * Parse cURL log messages
     *
     * @param RequestInterface $request Request that has a curl handle
     *
     * @return string
     */
    protected function parseCurlLog(RequestInterface $request)
    {
        $message = '';
        $handle = $request->getParams()->get('curl_handle');
        $stderr = $handle->getStderr(true);
        if ($stderr) {
            rewind($stderr);
            while ($line = fgets($stderr)) {
                // * - Debug | < - Downstream | > - Upstream
                if ($line[0] == '*') {
                    if ($this->settings & self::LOG_DEBUG) {
                        $message .= $line;
                    }
                } elseif ($this->settings & self::LOG_HEADERS) {
                    $message .= $line;
                }
                // Add the request body if needed
                if ($this->settings & self::LOG_BODY) {
                    if (trim($line) == '' && $request instanceof EntityEnclosingRequestInterface) {
                        if ($request->getParams()->get('request_wire')) {
                            $message .= (string) $request->getParams()->get('request_wire') . "\r\n";
                        } else {
                            $message .= (string) $request->getBody() . "\r\n";
                        }
                    }
                }
            }
        }

        return $message;
    }
}
