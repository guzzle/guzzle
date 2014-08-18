========
Adapters
========

Guzzle uses *adapters* to send HTTP requests. Adapters emit the lifecycle
events of requests, transfer HTTP requests, and normalize error handling.

Default Adapter
===============

Guzzle will use the best possible adapter based on your environment.

If cURL is present, Guzzle will use the following adapters by default:

- ``GuzzleHttp\Adapter\Curl\MultiAdapter`` is used to transfer requests in
  parallel.
- If ``allow_url_fopen`` is enabled, then a
  ``GuzzleHttp\Adapter\StreamingProxyAdapter`` is added so that streaming
  requests are sent using the PHP stream wrapper. If this setting is disabled,
  then streaming requests are sent through a cURL adapter.
- If using PHP 5.5 or greater, then a ``GuzzleHttp\Adapter\Curl\CurlAdapter``
  is used to send serial requests. Otherwise, the
  ``GuzzleHttp\Adapter\Curl\MultiAdapter`` is used for serial and parallel
  requests.

If cURL is not installed, then Guzzle will use a
``GuzzleHttp\Adapter\StreamingAdapter`` to send requests through PHP's
HTTP stream wrapper. ``allow_url_fopen`` must be enabled if cURL is not
installed on your system.

Creating an Adapter
===================

Creating a custom HTTP adapter allows you to completely customize the way an
HTTP request is sent over the wire. In some cases, you might need to use a
different mechanism for transferring HTTP requests other than cURL or PHP's
stream wrapper. For example, you might need to use a socket because the version
of cURL on your system has an old bug, maybe you'd like to implement future
response objects, or you want to create a thread pool and send parallel
requests using pthreads.

The first thing you need to know about implementing custom adapters are the
responsibilities of an adapter.

Adapter Responsibilities
------------------------

Adapters use a ``GuzzleHttp\Adapter\TransactionInterface`` which acts as a
mediator between ``GuzzleHttp\Message\RequestInterface`` and
``GuzzleHttp\Message\ResponseInterface`` objects. The main goal of an adapter
is to set a response on the provided transaction object.

1. The adapter MUST return a ``GuzzleHttp\Message\ResponseInterface`` object in
   a successful condition.

2. When preparing requests, adapters MUST properly handle as many of the
   following request configuration options as possible:

   - :ref:`cert-option`
   - :ref:`connect_timeout-option`
   - :ref:`debug-option`
   - :ref:`expect-option`
   - :ref:`proxy-option`
   - :ref:`save_to-option`
   - :ref:`ssl_key-option`
   - :ref:`stream-option`
   - :ref:`timeout-option`
   - :ref:`verify-option`
   - :ref:`decode_content` - When set to ``true``, the adapter must attempt to
     decode the body of a ``Content-Encoding`` response (e.g., gzip).

3. Adapters SHOULD not follow redirects. In the normal case, redirects are
   followed by ``GuzzleHttp\Subscriber\Redirect``. Redirects SHOULD be
   implemented using Guzzle event subscribers, not by an adapter.

4. The adapter MUST emit a ``before`` event with a
   ``GuzzleHttp\Event\BeforeEvent`` object before sending a request. If the
   event is intercepted and a response is associated with a transaction during
   the ``before`` event, then the adapter MUST not send the request over the
   wire, but rather return the response.

5. When all of the headers of a response have been received, the adapter MUST
   emit a ``headers`` event with a ``GuzzleHttp\Event\HeadersEvent``. This
   event MUST be emitted before any data is written to the body of the response
   object. It is important to keep in mind that event listeners MAY mutate a
   response during the emission of this event.

6. The adapter MUST emit a ``complete`` event with a
   ``GuzzleHttp\Event\CompleteEvent`` when a request has completed sending.
   Adapters MUST emit the complete event for all valid HTTP responses,
   including responses that resulted in a non 2xx level response.

7. The adapter MUST emit an ``error`` event with a
   ``GuzzleHttp\Event\ErrorEvent``when an error occurs during the transfer.
   This includes when preparing a request for transfer, during the ``before``
   event, during the ``headers`` event, during the ``complete`` event, when
   a networking error occurs, and so on.

8. After emitting the ``error`` event, the adapter MUST check if the
   error event was intercepted and a response was associated with the
   transaction. If the propagation of the ``error`` event was not stopped, then
   the adapter MUST throw the exception. If the propagation was stopped, then
   the adapter MUST NOT throw the exception.

Parallel Adapters
-----------------

Parallel adapters are used when using a client's ``sendAll()`` method. Parallel
adapters are expected to send one or more transactions in parallel. Parallel
adapters accept an ``\Iterator`` that yields
``GuzzleHttp\Adapter\TransactionInterface`` object. In addition to the
iterator, the adapter is also provided an integer representing the number of
transactions to execute in parallel.

Parallel adapters are similar to adapters (described earlier), except for the
following:

1. RequestExceptions are only thrown from a parallel adapter when the
   ``GuzzleHttp\Exception\RequestException::getThrowImmediately()`` method of
   an encountered exception returns ``true``. If this method does not return
   ``true`` or the exception is not an instance of RequestException, then the
   parallel adapter MUST NOT throw the exception. Error handling for parallel
   transfers should normally be handled through event listeners that use
   ``error`` events.

2. Parallel adapters are not expected to return responses. Because parallel
   adapters can, in theory, send an infinite number of requests, developers
   must use event listeners to receive the ``complete`` event and handle
   responses accordingly.

Emitting Lifecycle Events
-------------------------

Request lifecycle events MUST be emitted by adapters and parallel adapters.
These lifecycle events are used by event listeners to modify requests, modify
responses, perform validation, and anything else required by an application.

Emitting request lifecycle events in an adapter is much simpler if you use the
static helper method of ``GuzzleHttp\Event\RequestEvents``. These methods are
used by the built-in in curl and stream wrapper adapters of Guzzle, so you
should use them too.

Example Adapter
===============

Here's a really simple example of creating a custom HTTP adapter. For
simplicity, this example uses a magic ``send_request()`` function.

.. code-block:: php

    <?php

    namespace MyProject\Adapter;

    use GuzzleHttp\Event\RequestEvents;
    use GuzzleHttp\Event\HeadersEvent;
    use GuzzleHttp\Message\MessageFactoryInterface;

    class MyAdapter implements AdapterInterface
    {
        private $messageFactory;

        public function __construct(MessageFactoryInterface $messageFactory)
        {
            $this->messageFactory = $messageFactory;
        }

        public function send(TransactionInterface $transaction)
        {
            RequestEvents::emitBefore($transaction);

            // Check if the transaction was intercepted
            if (!$transaction->getResponse()) {
                // It wasn't intercepted, so send the request
                $this->getResponse($transaction);
            }

            // Adapters always return a response in the successful case.
            return $transaction->getResponse();
        }

        private function getResponse(TransactionInterface $transaction)
        {
            $request = $transaction->getRequest();

            $response = send_request(
                $request->getMethod(),
                $request->getUrl(),
                $request->getHeaders(),
                $request->getBody()
            );

            if ($response) {
                $this->processResponse($response, $transaction);
            } else {
                // Emit the error event which allows listeners to intercept
                // the error with a valid response. If it is not intercepted,
                // a RequestException is thrown.
                RequestEvents::emitError($transaction, $e);
            }
        }

        private function processResponse(
            array $response,
            TransactionInterface $transaction
        ) {
            // Process the response, create a Guzzle Response object, and
            // associate the response with the transaction.
            $responseObject = $this->messageFactory->createResponse(
                $response['status_code'],
                $response['headers']
            );

            $transaction->setResponse($responseObject);

            // Emit the headers event before downloading the body
            RequestEvents::emitHeaders($transaction);

            if ($response['body']) {
                // Assuming the response body is a stream or something,
                // associate it with the response object.
                $responseObject->setBody(Stream::factory($response['body']));
            }

            // Emit the complete event
            RequestEvents::emitComplete($transaction);
        }
    }
