<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Log\LogAdapterInterface;
use Guzzle\Common\Event\Observer;
use Guzzle\Common\Event\Subject;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;

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
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class LogPlugin implements Observer
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
     * @param int $settings (optional) Bitwise settings to use for logging
     */
    public function __construct(LogAdapterInterface $logAdapter, $settings = self::LOG_CONTEXT)
    {
        $this->logAdapter = $logAdapter;
        $this->hostname = gethostname();
        $this->setSettings($settings);
    }

    /**
     * Change the log settings of the plugin
     *
     * @param int $settings (optional) Bitwise settings to control what's logged
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
     * {@inheritdoc}
     */
    public function update(Subject $subject, $event, $context = null)
    {
        // @codeCoverageIgnoreStart
        if (!($subject instanceof RequestInterface)) {
            return;
        }
        // @codeCoverageIgnoreEnd
            
        /* @var $subject EntityEnclosingRequest */
        switch ($event) {
            case 'curl.callback.write':
                // Stream the response body to the log if the body is not repeatable
                if ($subject->getParams()->get('response_wire')) {
                    $subject->getParams()->get('response_wire')->write($context);
                }
                break;
            case 'curl.callback.read':
                // Stream the request body to the log if the body is not repeatable
                if ($subject->getParams()->get('request_wire')) {
                    $subject->getParams()->get('request_wire')->write($context);
                }
                break;
            case 'request.before_send':
                // We need to make special handling for content wiring and
                // non-repeatable streams.
                if ($this->settings & self::LOG_BODY) {
                    if ($subject instanceof EntityEnclosingRequestInterface) {
                        if ($subject->getBody() && (!$subject->getBody()->isSeekable() || !$subject->getBody()->isReadable())) {
                            // The body of the request cannot be recalled so
                            // logging the content of the request will need to
                            // be streamed using updates
                            $subject->getParams()->set('request_wire', EntityBody::factory(''));
                        }
                    }
                    if (!$subject->isResponseBodyRepeatable()) {
                        // The body of the response cannot be recalled so
                        // logging the content of the response will need to
                        // be streamed using updates
                        $subject->getParams()->set('response_wire', EntityBody::factory(''));
                    }
                }
                break;
            case 'request.curl.release':
                // Triggers the actual log write
                $this->log($subject, $subject->getResponse());
                break;
        }
    }

    /**
     * Log a message based on a request and response
     *
     * @param RequestInterface $request Request to log
     * @param Response $response (optional) Response to log
     */
    private function log(RequestInterface $request, Response $response = null)
    {
        $priority = $response && !$response->isSuccessful() ? LOG_ERR : LOG_DEBUG;
        $message = '';

        if ($this->settings & self::LOG_CONTEXT) {
            // Log common contextual information
            $message = $request->getHost() . ' - "' .  $request->getMethod()
                . ' ' . $request->getResourceUri() . ' '
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
        if ($request->getCurlHandle() && ($this->settings & self::LOG_DEBUG || $this->settings & self::LOG_HEADERS || $this->settings & self::LOG_BODY)) {

            // If context logging too, then add a new line for cleaner messages
            if ($this->settings & self::LOG_CONTEXT) {
                $message .= "\n";
            }

            // Filter cURL's verbose output based on config settings
            $stderr = $request->getCurlHandle()->getStderr(true);
            rewind($stderr);
            $addedBody = false;
            while ($line = fgets($stderr)) {
                $first = $line[0];
                // * - Debug | < - Downstream | > - Upstream
                if ($line[0] == '*' && $this->settings & self::LOG_DEBUG) {
                    $message .= $line;
                } else if ($this->settings & self::LOG_HEADERS) {
                    $message .= $line;
                }
                // Add the request body if needed
                if ($this->settings & self::LOG_BODY) {
                    if (trim($line) == '' && !$addedBody && $request instanceof EntityEnclosingRequestInterface) {
                        $message .= $request->getParams()->get('request_wire')
                            ? (string) $request->getParams()->get('request_wire') . "\r\n"
                            : (string) $request->getBody() . "\r\n";
                        $addedBody = true;
                    }
                }
            }

            // Log the response body if the response is available
            if ($this->settings & self::LOG_BODY && $response) {
                $message .= $request->getParams()->get('response_wire')
                    ? (string) $request->getParams()->get('response_wire')
                    : $response->getBody(true);
            }
        }

        // Send the log message to the adapter, adding a category and host
        $this->logAdapter->log(trim($message), $priority, array(
            'category' => 'guzzle.request',
            'host' => $this->hostname
        ));
    }
}