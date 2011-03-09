<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Log\Logger;
use Guzzle\Common\Event\Observer;
use Guzzle\Common\Event\Subject;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;

/**
 * Plugin class that will add request and response logging to an HTTP request
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class LogPlugin implements Observer
{
    const WIRE_HEADERS = 1;
    const WIRE_FULL = 2;

    /**
     * @var Logger Logger object used to delegate log messages to adapters
     */
    private $logger;

    /**
     * @var int Level of data to log when logging wire data
     */
    private $wireLevel = false;

    /**
     * @var bool Whether or not to log contextual request/response information
     */
    private $logContext = false;

    /**
     * @var string Cached copy of the hostname
     */
    private $hostname;

    /**
     * Construct a new LogPlugin
     *
     * @param Logger $logger Object used to delegate log messages to adapters
     * @param bool $logContext (optional) Set to TRUE or FALSE to log contextual info
     * @param bool $wireLevel (optional) Set to WIRE_HEADERS to log header data
     *      sent over the wire.  Set to WIRE_FULL to log header and content data
     *      sent over the wire.
     */
    public function __construct(Logger $logger, $logContext = true, $wireLevel = false)
    {
        $this->logger = $logger;
        $this->logContext = $logContext;
        $this->wireLevel = $wireLevel;
        $this->hostname = gethostname();
    }

    /**
     * Get the logger object
     *
     * @return Logger
     */
    public function getLogger()
    {
        return $this->logger;
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

            case 'request.before_send':

                // We need to make special handling for content wiring and
                // non-repeatable streams.
                if ($this->wireLevel == self::WIRE_FULL) {

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

            case 'request.success':

                $this->log($subject, $context);
                break;

            case 'request.failure':

                // Log curl exception messages
                $this->log($subject, $context->getResponse(), $context->getMessage());

                break;

            case 'curl.callback.write':
                // Stream the response body as it is read using cURL
                if ($subject->getParams()->get('response_wire')) {
                    $subject->getParams()->get('response_wire')->write($context);
                }
                break;

            case 'curl.callback.read':
                // Stream the request body as it is read using cURL
                if ($subject->getParams()->get('request_wire')) {
                    $subject->getParams()->get('request_wire')->write($context);
                }
                break;
        }
    }

    /**
     * Send a message to the logger based on a request and response
     *
     * @param RequestInterface $request Request to log
     * @param Response $response (optional) Response to log
     * @param string $moreInfo (optional) More information to log
     */
    private function log(RequestInterface $request, Response $response = null, $moreInfo = null)
    {
        $priority = ($response && !$response->isSuccessful()) ? LOG_ERR : LOG_DEBUG;
        $message = '';

        if ($this->logContext) {

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

        if ($this->wireLevel) {

            // If context logging too, then add a new line
            if ($this->logContext) {
                $message .= "\n";
            }

            if ($this->wireLevel == self::WIRE_HEADERS) {
                $message .= $request->getRawHeaders();
            } else {
                $message .= (string) $request;
                if ($request->getParams()->get('request_wire')) {
                    $message .= (string) $request->getParams()->get('request_wire');
                }
            }

            if ($response) {

                $message .= "\n\n";

                if ($this->wireLevel == self::WIRE_HEADERS) {
                    $message .= $response->getRawHeaders();
                } else {
                    $message .= (string) $response;
                    if ($request->getParams()->get('response_wire')) {
                        $message .= (string) $request->getParams()->get('response_wire');
                    }
                }
            }
        }

        if ($moreInfo) {
            $message .= "\n" . $moreInfo;
        }

        $this->logger->log(trim($message), $priority, 'guzzle_request', $this->hostname);
    }
}