<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Event\AbstractTransferStatsEvent;

/**
 * Strategy used to retry when certain cURL error codes are encountered.
 */
class CurlResultFilter extends AbstractRetryFilter
{
    /** @var array Default cURL errors to retry */
    protected static $defaultErrorCodes = array(
        CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_PARTIAL_FILE, CURLE_WRITE_ERROR, CURLE_READ_ERROR,
        CURLE_OPERATION_TIMEOUTED, CURLE_SSL_CONNECT_ERROR, CURLE_HTTP_PORT_FAILED, CURLE_GOT_NOTHING,
        CURLE_SEND_ERROR, CURLE_RECV_ERROR
    );

    protected function should($retries, AbstractTransferStatsEvent $event)
    {
        return isset($this->errorCodes[(int) $event->getTransferInfo('curl_result')]);
    }
}
