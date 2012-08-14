<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Common\Collection;
use Guzzle\Common\Log\LogAdapterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs exponential backoff retries triggered from the ExponentialBackoffPlugin
 *
 * Format your log messages using a template that can contain the the following variables:
 *
 * - {ts}:           Timestamp
 * - {method}:       Method of the request
 * - {url}:          URL of the request
 * - {retries}:      Current number of retries for the request
 * - {delay}:        Amount of time the request is being delayed before being retried
 * - {code}:         Status code of the response (if available)
 * - {phrase}:       Reason phrase of the response  (if available)
 * - {curl_error}:   Curl error message (if available)
 * - {curl_code}:    Curl error code (if available)
 * - {connect_time}: Time in seconds it took to establish the connection (if available)
 * - {total_time}:   Total transaction time in seconds for last transfer (if available)
 * - {header_*}:     Replace `*` with the lowercased name of a header to add to the message
 */
class ExponentialBackoffLogger implements EventSubscriberInterface
{
    /**
     * @var string Default log message template
     */
    const DEFAULT_FORMAT = '[{ts}] {method} {url} - {code} {phrase} - Retries: {retries}, Delay: {delay}, Time: {connect_time}, {total_time}, cURL: {curl_code} {curl_error}';

    /**
     * @var LogAdapterInterface Logger used to log retries
     */
    protected $logger;

    /**
     * @var string Template used to format log messages
     */
    protected $template;

    /**
     * Exponential backoff retry logger
     *
     * @param LogAdapterInterface $logger   Logger used to log the retries
     * @param string              $template Log message template
     */
    public function __construct(LogAdapterInterface $logger, $template = self::DEFAULT_FORMAT)
    {
        $this->logger = $logger;
        $this->template = $template;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(ExponentialBackoffPlugin::RETRY_EVENT => 'onRequestRetry');
    }

    /**
     * Set the template to use for logging
     *
     * @param string $template Log message template
     *
     * @return self
     */
    public function setTemplate($template)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Called when a request is being retried
     *
     * @param Event $event Event emitted
     */
    public function onRequestRetry(Event $event)
    {
        $request = $event['request'];
        $response = $event['response'];
        $handle = $event['handle'];

        $data = new Collection(array(
            'ts'      => gmdate('c'),
            'method'  => $request->getMethod(),
            'url'     => $request->getUrl(),
            'retries' => $event['retries'],
            'delay'   => $event['delay']
        ));

        if ($response) {
            $data->merge(array(
                'code'         => $response->getStatusCode(),
                'phrase'       => $response->getReasonPhrase(),
                'connect_time' => $response->getInfo('connect_time'),
                'total_time'   => $response->getInfo('total_time'),
            ));
        }

        if ($handle) {
            $data->set('curl_error', $handle->getError());
            $data->set('curl_code', $handle->getErrorNo());
        }

        // Add request headers to the possible template values
        foreach ($request->getHeaders(true) as $header => $value) {
            $data->set("header_{$header}", (string) $value);
        }

        $this->logger->log($data->inject($this->template), LOG_INFO, $data);
    }
}
