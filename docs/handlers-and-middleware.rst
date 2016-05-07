=======================
Handlers and Middleware
=======================

Guzzle clients use a handler and middleware system to send HTTP requests.

Handlers
========

A handler function accepts a ``Psr\Http\Message\RequestInterface`` and array of
request options and returns a ``GuzzleHttp\Promise\PromiseInterface`` that is
fulfilled with a ``Psr\Http\Message\ResponseInterface`` or rejected with an
exception.

You can provide a custom handler to a client using the ``handler`` option of
a client constructor. It is important to understand that several request
options used by Guzzle require that specific middlewares wrap the handler used
by the client. You can ensure that the handler you provide to a client uses the
default middlewares by wrapping the handler in the
``GuzzleHttp\HandlerStack::create(callable $handler = null)`` static method.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Handler\CurlHandler;

    $handler = new CurlHandler();
    $stack = HandlerStack::create($handler); // Wrap w/ middleware
    $client = new Client(['handler' => $stack]);

The ``create`` method adds default handlers to the ``HandlerStack``. When the
``HandlerStack`` is resolved, the handlers will execute in the following order:

1. Sending request:

  1. ``http_errors`` - No op when sending a request. The response status code
     is checked in the response processing when returning a response promise up
     the stack.
  2. ``allow_redirects`` - No op when sending a request. Following redirects
     occurs when a response promise is being returned up the stack.
  3. ``cookies`` - Adds cookies to requests.
  4. ``prepare_body`` - The body of an HTTP request will be prepared (e.g.,
     add default headers like Content-Length, Content-Type, etc.).
  5. <send request with handler>

2. Processing response:

  1. ``prepare_body`` - no op on response processing.
  2. ``cookies`` - extracts response cookies into the cookie jar.
  3. ``allow_redirects`` - Follows redirects.
  4. ``http_errors`` - throws exceptions when the response status code ``>=``
     300.

When provided no ``$handler`` argument, ``GuzzleHttp\HandlerStack::create()``
will choose the most appropriate handler based on the extensions available on
your system.

.. important::

    The handler provided to a client determines how request options are applied
    and utilized for each request sent by a client. For example, if you do not
    have a cookie middleware associated with a client, then setting the
    ``cookies`` request option will have no effect on the request.


Middleware
==========

Middleware augments the functionality of handlers by invoking them in the
process of generating responses. Middleware is implemented as a higher order
function that takes the following form.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;

    function my_middleware()
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                return $handler($request, $options);
            };
        };
    }

Middleware functions return a function that accepts the next handler to invoke.
This returned function then returns another function that acts as a composed
handler-- it accepts a request and options, and returns a promise that is
fulfilled with a response. Your composed middleware can modify the request,
add custom request options, and modify the promise returned by the downstream
handler.

Here's an example of adding a header to each request.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;

    function add_header($header, $value)
    {
        return function (callable $handler) use ($header, $value) {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler, $header, $value) {
                $request = $request->withHeader($header, $value);
                return $handler($request, $options);
            };
        };
    }

Once a middleware has been created, you can add it to a client by either
wrapping the handler used by the client or by decorating a handler stack.

.. code-block:: php

    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Handler\CurlHandler;
    use GuzzleHttp\Client;

    $stack = new HandlerStack();
    $stack->setHandler(new CurlHandler());
    $stack->push(add_header('X-Foo', 'bar'));
    $client = new Client(['handler' => $stack]);

Now when you send a request, the client will use a handler composed with your
added middleware, adding a header to each request.

Here's an example of creating a middleware that modifies the response of the
downstream handler. This example adds a header to the response.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseInterface;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Handler\CurlHandler;
    use GuzzleHttp\Client;

    function add_response_header($header, $value)
    {
        return function (callable $handler) use ($header, $value) {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler, $header, $value) {
                $promise = $handler($request, $options);
                return $promise->then(
                    function (ResponseInterface $response) use ($header, $value) {
                        return $response->withHeader($header, $value);
                    }
                );
            };
        };
    }

    $stack = new HandlerStack();
    $stack->setHandler(new CurlHandler());
    $stack->push(add_response_header('X-Foo', 'bar'));
    $client = new Client(['handler' => $stack]);

Creating a middleware that modifies a request is made much simpler using the
``GuzzleHttp\Middleware::mapRequest()`` middleware. This middleware accepts
a function that takes the request argument and returns the request to send.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Handler\CurlHandler;
    use GuzzleHttp\Client;
    use GuzzleHttp\Middleware;

    $stack = new HandlerStack();
    $stack->setHandler(new CurlHandler());

    $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
        return $request->withHeader('X-Foo', 'bar');
    }));

    $client = new Client(['handler' => $stack]);

Modifying a response is also much simpler using the
``GuzzleHttp\Middleware::mapResponse()`` middleware.

.. code-block:: php

    use Psr\Http\Message\ResponseInterface;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Handler\CurlHandler;
    use GuzzleHttp\Client;
    use GuzzleHttp\Middleware;

    $stack = new HandlerStack();
    $stack->setHandler(new CurlHandler());

    $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
        return $response->withHeader('X-Foo', 'bar');
    }));

    $client = new Client(['handler' => $stack]);


HandlerStack
============

A handler stack represents a stack of middleware to apply to a base handler
function. You can push middleware to the stack to add to the top of the stack,
and unshift middleware onto the stack to add to the bottom of the stack. When
the stack is resolved, the handler is pushed onto the stack. Each value is
then popped off of the stack, wrapping the previous value popped off of the
stack.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Middleware;
    use GuzzleHttp\Client;

    $stack = new HandlerStack();
    $stack->setHandler(\GuzzleHttp\choose_handler());

    $stack->push(Middleware::mapRequest(function (RequestInterface $r) {
        echo 'A';
        return $r;
    });

    $stack->push(Middleware::mapRequest(function (RequestInterface $r) {
        echo 'B';
        return $r;
    });

    $stack->push(Middleware::mapRequest(function (RequestInterface $r) {
        echo 'C';
        return $r;
    });

    $client->request('GET', 'http://httpbin.org/');
    // echoes 'ABC';

    $stack->unshift(Middleware::mapRequest(function (RequestInterface $r) {
        echo '0';
        return $r;
    });

    $client = new Client(['handler' => $stack]);
    $client->request('GET', 'http://httpbin.org/');
    // echoes '0ABC';

You can give middleware a name, which allows you to add middleware before
other named middleware, after other named middleware, or remove middleware
by name.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;
    use GuzzleHttp\Middleware;

    // Add a middleware with a name
    $stack->push(Middleware::mapRequest(function (RequestInterface $r) {
        return $r->withHeader('X-Foo', 'Bar');
    }, 'add_foo');

    // Add a middleware before a named middleware (unshift before).
    $stack->before('add_foo', Middleware::mapRequest(function (RequestInterface $r) {
        return $r->withHeader('X-Baz', 'Qux');
    }, 'add_baz');

    // Add a middleware after a named middleware (pushed after).
    $stack->after('add_baz', Middleware::mapRequest(function (RequestInterface $r) {
        return $r->withHeader('X-Lorem', 'Ipsum');
    });

    // Remove a middleware by name
    $stack->remove('add_foo');


Creating a Handler
==================

As stated earlier, a handler is a function accepts a
``Psr\Http\Message\RequestInterface`` and array of request options and returns
a ``GuzzleHttp\Promise\PromiseInterface`` that is fulfilled with a
``Psr\Http\Message\ResponseInterface`` or rejected with an exception.

A handler is responsible for applying the following :doc:`request-options`.
These request options are a subset of request options called
"transfer options".

- :ref:`cert-option`
- :ref:`connect_timeout-option`
- :ref:`debug-option`
- :ref:`delay-option`
- :ref:`decode_content-option`
- :ref:`expect-option`
- :ref:`proxy-option`
- :ref:`sink-option`
- :ref:`timeout-option`
- :ref:`ssl_key-option`
- :ref:`stream-option`
- :ref:`verify-option`
