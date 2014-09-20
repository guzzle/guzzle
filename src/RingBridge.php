<?php
namespace GuzzleHttp;

use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Event\ProgressEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Stream\Stream;

/**
 * Provides the bridge between Guzzle requests and responses and Guzzle Ring.
 */
class RingBridge
{
    /**
     * Creates a Ring request from a request object.
     *
     * This function does not hook up the "then" and "progress" events that
     * would be required for actually sending a Guzzle request through a
     * ring adapter.
     *
     * @param RequestInterface $request Request to convert.
     *
     * @return array Converted Guzzle Ring request.
     */
    public static function createRingRequest(RequestInterface $request)
    {
        $options = $request->getConfig()->toArray();
        $url = $request->getUrl();
        // No need to calculate the query string twice (in URL and query).
        $qs = ($pos = strpos($url, '?')) ? substr($url, $pos + 1) : null;

        return [
            'scheme'       => $request->getScheme(),
            'http_method'  => $request->getMethod(),
            'url'          => $url,
            'uri'          => $request->getPath(),
            'headers'      => $request->getHeaders(),
            'body'         => $request->getBody(),
            'version'      => $request->getProtocolVersion(),
            'client'       => $options,
            'query_string' => $qs,
            'future'       => isset($options['future']) ? $options['future'] : false
        ];
    }

    /**
     * Give a ring request array, this function adds the "then" and "progress"
     * event callbacks using a transaction and message factory.
     *
     * @param array                   $ringRequest Request to update.
     * @param Transaction             $trans       Transaction
     * @param MessageFactoryInterface $factory     Creates responses.
     * @param Fsm                     $fsm         State machine.
     *
     * @return array Returns the new ring response array.
     */
    public static function addRingRequestCallbacks(
        array $ringRequest,
        Transaction $trans,
        MessageFactoryInterface $factory,
        Fsm $fsm
    ) {
        $request = $trans->request;

        $ringRequest['then'] = function (array $response) use ($trans, $factory, $fsm) {
            self::completeRingResponse($trans, $response, $factory, $fsm);
        };

        // Emit progress events if any progress listeners are registered.
        if ($request->getEmitter()->hasListeners('progress')) {
            $emitter = $request->getEmitter();
            $ringRequest['client']['progress'] = function ($a, $b, $c, $d)
                use ($trans, $emitter)
            {
                $emitter->emit(
                    'progress',
                    new ProgressEvent($trans, $a, $b, $c, $d)
                );
            };
        }

        return $ringRequest;
    }

    /**
     * Creates a Ring request from a request object AND prepares the callbacks.
     *
     * @param Transaction             $transaction Transaction to update.
     * @param MessageFactoryInterface $factory     Creates responses.
     * @param Fsm                     $fsm         State machine.
     *
     * @return array Converted Guzzle Ring request.
     */
    public static function prepareRingRequest(
        Transaction $transaction,
        MessageFactoryInterface $factory,
        Fsm $fsm
    ) {
        // Clear out the transaction state when initiating.
        $transaction->exception = null;

        return self::addRingRequestCallbacks(
            self::createRingRequest($transaction->request),
            $transaction,
            $factory,
            $fsm
        );
    }

    /**
     * Handles the process of processing a response received from a ring
     * handler. The created response is added to the transaction, and any
     * necessary events are emitted based on the ring response.
     *
     * @param Transaction             $trans          Owns request and response.
     * @param array                   $response       Ring response array
     * @param MessageFactoryInterface $messageFactory Creates response objects.
     * @param Fsm                     $fsm            State machine.
     */
    public static function completeRingResponse(
        Transaction $trans,
        array $response,
        MessageFactoryInterface $messageFactory,
        Fsm $fsm
    ) {
        if (!empty($response['status'])) {
            $options = [];
            if (isset($response['version'])) {
                $options['protocol_version'] = $response['version'];
            }
            if (isset($response['reason'])) {
                $options['reason_phrase'] = $response['reason'];
            }
            $trans->response = $messageFactory->createResponse(
                $response['status'],
                $response['headers'],
                isset($response['body']) ? $response['body'] : null,
                $options
            );
            if (isset($response['effective_url'])) {
                $trans->response->setEffectiveUrl($response['effective_url']);
            }
        }

        $trans->transferInfo = isset($response['transfer_info'])
            ? $response['transfer_info'] : [];

        // Determine which state to transition to.
        if (!isset($response['error'])) {
            $trans->state = 'complete';
        } else {
            $trans->exception = $response['error'];
            $trans->state = 'error';
        }

        // Complete the lifecycle of the request.
        $fsm->run($trans);
    }

    /**
     * Creates a Guzzle request object using a ring request array.
     *
     * @param array $request Ring request
     *
     * @return Request
     * @throws \InvalidArgumentException for incomplete requests.
     */
    public static function fromRingRequest(array $request)
    {
        $options = [];
        if (isset($request['version'])) {
            $options['protocol_version'] = $request['version'];
        }

        if (!isset($request['http_method'])) {
            throw new \InvalidArgumentException('No http_method');
        }

        return new Request(
            $request['http_method'],
            Core::url($request),
            isset($request['headers']) ? $request['headers'] : [],
            isset($request['body']) ? Stream::factory($request['body']) : null,
            $options
        );
    }
}
