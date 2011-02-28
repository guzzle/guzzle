# Guzzle is a PHP framework for building RESTful web service clients

Most web service clients follow a specific pattern: create a client class, create methods for each action that can be taken on the API, create a cURL handle to transfer an HTTP request to the client, and return the response.  You've probably had to interact with an API that either doesn't have a PHP webservice client or the currently available PHP clients are not up to an acceptable level of quality.  When facing these types of situations, you probably find yourself writing a webservice client that lacks advanced features such as persistent HTTP connections, parallel requests, over the wire logging, a PHP based caching forward proxy, a service configuration object, or exponential backoff.  Why would you spend the amount of time required to write those features though?  It's just a simple webservice client for just one of the many APIs you might be using.  But then you build another client... and another.  Suddenly you find yourself with several web service clients to maintain, each reeking of code duplication and lacking most, if not all, of the aforementioned features.  Enter Guzzle.

Guzzle gives PHP developers a simple to extend framework for building robust webservice clients that helps to eliminate code duplication by leveraging the patterns and solutions already coded into the framework.

## Guzzle gives PHP developers complete control over HTTP requests while utilizing HTTP/1.1 best practices.

### Control over HTTP requests

Guzzle gives PHP developers a tremendous amount of control over HTTP requests.  Guzzle's HTTP namespace provides a wrapper over the PHP libcurl bindings.  Most of the functionality implemented in the libcurl bindings has been simplified and abstracted by Guzzle.  Developers who need access to cURL specific functionality that is not abstracted by Guzzle (e.g. proxies) can still add cURL handle specific behavior to Guzzle HTTP requests.

### Send HTTP requests in parallel

Guzzle helps PHP developers utilize HTTP/1.1 best practices including sending requests in parallel and utilizing persistent (keep-alive) HTTP connections.  Sending many HTTP requests serially (one at a time) can cause an unnecessary delay in a script's execution.  Each request must complete before a subsequent request can be sent.  By sending requests in parallel, a pool of HTTP requests can complete at the speed of the slowest request in the pool, significantly reducing the amount of time needed to execute multiple HTTP requests.  Guzzle provides a wrapper for the curl_multi functions in PHP.

### Managed persistent HTTP connections

Another extremely important aspect of the HTTP/1.1 protocol that is often overlooked by PHP webservice clients is persistent HTTP connections.  Persistent connections allows data to be transferred between a client and server without the need to reconnect each time a subsequent request is sent, providing a significant performance boost to applications that need to send many HTTP requests to the same host.

HTTP requests and cURL handles are separate entities in Guzzle.  In order for a request to get a cURL handle to transfer its message to a server, a request retrieves a cURL handle from a cURL handle factory.  The default cURL handle factory will maintain a pool of open cURL handles and return an already existent cURL handle (with a persistent HTTP connection) if available, or create a new cURL handle.

### Plugins for common HTTP request behavior

#### Over the wiring logging

Easily troubleshoot errors by logging everything that goes over the wire upstream and downstream, including redirect requests and entity bodies.

#### Truncated exponential backoff

Automatically retries HTTP requests using truncated exponential backoff.

#### PHP-based caching forward proxy

Provides a simple private transparent proxy cache that caches HTTP responses using HTTP headers to control caching behavior, cache lifetime, and supports ETag and Last-Modified based revalidation.

#### Cookie session plugin

Maintains cookies between HTTP requests.  Supports cookie version 1 and 2.

## Guzzle enables PHP developers to quickly create web service clients.

PHP webservice clients can be created in one of two ways in Guzzle: using a concrete client or a dynamic client.  Concrete clients use a Command class for each action that can be taken on a webservice, while dynamic clients dynamically build HTTP requests based on an XML service description.  Concrete clients are more feature rich and testable than dynamic clients, while dynamic clients are quicker to implement.

## Guzzle provides a catalog of fully featured web service clients.

Guzzle currently provides web service clients for Amazon S3, Amazon SQS, Amazon SimpleDB, Amazon MWS, Cardinal Commerce, and Unfuddle.  Many more APIs are to follow, and hopefully other developers can contribute to the growing catalog of Guzzle webservice clients.

## Guzzle is extremely well tested

Guzzle is used in production a mutli-million dollar e-commerce company.  Guzzle has 100% code coverage; every line of Guzzle has been tested using PHPUnit.

## Quick examples

### GET the google homepage

    <?php

    use Guzzle\Http\Message\RequestFactory;

    // Use the RequestFactory to create a GET request
    $message = RequestFactory::getInstance()->newRequest('GET', 'http://www.google.com/');
    // Send the request and retrieve the response object
    $response = $message->send();
    echo $response->getStatusCode() . "\n";
    // Echo the raw HTTP request
    echo $request;
    // Echo the raw HTTP response
    echo $response;

### Clear the contents of an Amazon S3 bucket

    <?php

    use Guzzle\Service\Aws\S3\Command\Bucket\ClearBucket;

    $command = new ClearBucket();
    $command->setBucket('test');
    $client = $this->getServiceBuilder()->getClient('michael.s3');
    $client->execute($command);