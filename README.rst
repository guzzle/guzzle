Guzzle
======

Guzzle is a PHP framework for building REST webservice clients.  Guzzle provides the tools necessary to quickly build a testable webservice client with complete control over preparing HTTP requests and processing HTTP responses.

* Supports GET, HEAD, POST, DELETE, and PUT methods
* Persistent connections are implicitly managed by Guzzle, resulting in huge performance benefits
* Allows custom entity bodies to be sent in PUT and POST requests, including sending data from a PHP stream
* Allows full access to request HTTP headers
* Responses can be cached and served from cache using the CachePlugin
* Failed requests can be retried using truncated exponential backoff using the ExponentialBackoffPlugin
* All data sent over the wire can be logged using the LogPlugin
* Cookie sessions can be maintained between requests using the CookiePlugin
* Send requests in parallel
* Supports HTTPS and SSL certificate validation
* Requests can be sent through a proxy
* Automatically requests compressed data and automatically decompresses data
* Supports authentication methods provided by cURL (Basic, Digest, GSS Negotiate, NTLM)
* Transparently follows redirects
* Subject/Observer signal slot system for modifying request behavior
* Request signal slot events for before/progress/complete/failure/etc...

Guzzle makes writing services an easy task by providing a simple pattern to follow:

#. Extend the default client class
#. Create a client builder if needed
#. Create commands for each API action.  Guzzle uses the command pattern.
#. Add the service definition to your services.xml file

Most web service clients follow a specific pattern: create a client class, create methods for each action that can be taken on the API, create a cURL handle to transfer an HTTP request to the client, parse the response, implement error handling, and return the result. You've probably had to interact with an API that either doesn't have a PHP client or the currently available PHP clients are not up to an acceptable level of quality. When facing these types of situations, you probably find yourself writing a webservice that lacks most of the advanced features mentioned by Michael. It wouldn't make sense to spend all that time writing those features-- it's just a simple webservice client for just one API... But then you build another client... and another. Suddenly you find yourself with several web service clients to maintain, each client a God class, each reeking of code duplication and lacking most, if not all, of the aforementioned features. Enter Guzzle.

Guzzle is used in production at `SHOEBACCA.com <http://www.shoebacca.com/>`_, a mutli-million dollar e-commerce company.  Guzzle has 100% code coverage; every line of Guzzle has been tested using PHPUnit.

Installing Guzzle
-----------------

Install Guzzle using pear when using Guzzle in production::

    pear channel-discover pearhub.org
    pear install pearhub/guzzle

You will need to add Guzzle to your application's autoloader.  Guzzle ships with a few select classes from other vendors, one of which is the Symfony2 universal class loader.  If your application does not already use an autoloader, you can use the autoloader distributed with Guzzle::

    <?php

    require_once '/path/to/guzzle/library/vendor/Symfony/Component/ClassLoader/UniversalClassLoader.php';

    $classLoader = new \Symfony\Component\ClassLoader\UniversalClassLoader();
    $classLoader->registerNamespaces(array(
        'Guzzle' => '/path/to/guzzle/library'
    ));
    $classLoader->register();

Substitute '/path/to/' with the full path to your Guzzle installation.  You can find the PEAR installation folder using pear config-get php_dir

Installing services
-------------------

Current Services
~~~~~~~~~~~~~~~~

Guzzle services are distributed separately from the Guzzle framework.  Guzzle officially supports a few webservice clients (these clients are currently what we use at SHOEBACCA.com), and hopefully there will be third-party created services coming soon:

* `Amazon Webservices (AWS) <https://github.com/guzzle/guzzle-aws>`_

    * Amazon S3
    * Amazon SimpleDB
    * Amazon SQS
    * Amazon MWS

* `Unfuddle <https://github.com/guzzle/guzzle-unfuddle>`_
* `Cardinal Commerce <https://github.com/guzzle/guzzle-cardinal-commerce>`_

When installing a Guzzle service, check the service's installation instructions for specific examples on how to install the service.  Most services can be installed using a git submodule or, if available, a PEAR package through pearhub.org::

    pear install pearhub/guzzle-aws # Note: this might not work while we're still finalizing our deployment methods

Services can also be installed using git submodules::

    git submodule add git://github.com/guzzle/guzzle-aws.git /path/to/guzzle/library/Guzzle/Service/Aws

Autoloading Services
~~~~~~~~~~~~~~~~~~~~

Services that are installed within the path to Guzzle under the Service folder will be autoloaded automatically using the autoloader settings configured for the Guzzle library (e.g. /Guzzle/Service/Aws).  If you install a Guzzle service outside of this directory structure, you will need to add the service to the autoloader.

Using Services
--------------

Let's say you want to use the Amazon S3 client from the Guzzle AWS service.

1. Create a services.xml file:

Create a services.xml that your ServiceBuilder will use to create service clients.  The services.xml file defines the clients you will be using and the arguments that will be passed into the client when it is constructed.  Each client + arguments combination is given a name and  referenced by name when retrieving a client from the ServiceBuilder.::

    <?xml version="1.0" ?>
    <guzzle>
        <clients>
            <!-- Abstract service to store AWS account credentials -->
            <client name="test.abstract.aws">
                <param name="access_key_id" value="12345" />
                <param name="secret_access_key" value="abcd" />
            </client>
            <!-- Concrete Amazon S3 client -->
            <client name="test.s3" builder="Guzzle.Service.Aws.S3.S3Builder" extends="test.abstract.aws" />
        </clients>
    </guzzle>

2. Create a ServiceBuilder::

    <?php
    use Guzzle\Service\ServiceBuilder;

    $serviceBuilder = ServiceBuilder::factory('/path/to/services.xml');

3. Get the Amazon S3 client from the ServiceBuilder and execute a command::

    use Guzzle\Service\Aws\S3\Command\Object\GetObject;

    $client = $serviceBuilder->getClient('test.s3');
    $command = new GetObject();
    $command->setBucket('mybucket')->setKey('mykey');

    // The result of the GetObject command returns the HTTP response object
    $httpResponse = $client->execute($command);
    echo $httpResponse->getBody();

The GetObject command just returns the HTTP response object when it is executed.  Other commands might return more valuable information when executed::

    use Guzzle\Service\Aws\S3\Command\Bucket\ListBucket;

    $command = new ListBucket();
    $command->setBucket('mybucket');
    $objects = $client->execute($command);

    // Iterate over every single object in the bucket
    // subsequent requests will be issued to retrieve
    // the next result of a truncated response
    foreach ($objects as $object) {
        echo "{$object['key']} {$object['size']}\n";
    }

    // You can get access to the HTTP request issued by the command and the response
    echo $command->getRequest();
    echo $command->getResponse();

The ListBucket command above returns a BucketIterator which will iterate over the entire contents of a bucket.  As you can see, commands can be as simple or complex as you want.

If the above code samples seem a little verbose to you, you can take some shortcuts in your code by leveraging the Guzzle command factory inherent to each client::

    // Most succinctly
    $objects = $client->getCommand('bucket.list_bucket', array('bucket' => 'my_bucket'))->execute();

    // The best blend of verbose and succinct
    $objects = $client->getCommand('bucket.list_bucket')
        ->setBucket('my_bucket')
        ->execute();

Creating a simple web service client
------------------------------------

The Guzzle ``Guzzle\Service\Client`` object can be used directly with a simple web service.  Robust web service clients should interact with a web service using command objects, but if you want to quickly interact with a web service, you can create a client and build your HTTP requests manually.  When creating a simple client, pass the base URL of the web service to the client's constructor.  In the following example, we are interacting with the Unfuddle API and issuing a GET request to retrieve a listing of tickets in the 123 project::

    <?php
    use Guzzle\Service\Client;

    $client = new Client('https://mydomain.unfuddle.com/api/v1');
    $request = $client->get('projects/{{project_id}}/tickets', array(
        'project_id' => '123'
    ));

    $request->setAuth('myusername', 'mypassword');
    $response = $request->send();

Notice that the URI provided to the client's ``get`` method is relative.  The path in the URI is also relative.  Relative paths will add to the path of the base URL of the client-- so in the example above, the path of the base URL is ``/api/v1``, the relative path is ``projects/123/tickets``, and the URL will ultimately become ``https://mydomain.unfuddle.com/api/v1/projects/123/tickets``.  If a relative path and a query string are provided, then the relative path will be appended to the base URL path, and the query string provided will be merged into the query string of the base URL.  If an absolute path is provided (e.g. /path/to/something), then the path specified in the base URL of the client will be replaced with the absolute path, and the query string provided will replace the query string of the base URL.  If an absolute URL is provided (e.g. ``http://www.test.com/path``), then the request will completely use the absolute URL as-is without merging in any of the URL parts specified in the base URL.

Templates can be specified in the client's get, head, delete, post, and put methods, which allow placeholders to be specified in the the request template that will be overwritten with an array of configuration data referenced by key.

All requests in the above client would need the basic HTTP authorization added after they are created.  You can automate this and add the authorization header to all requests generated by the client by adding a custom event to the client's event manager::

    <?php

    $client->getEventManager()->attach(function($subject, $event, $context) {
        if ($event = 'request.create') {
            $context->setAuth('myusername', 'mypassword');
        }
    });

Examples of sending HTTP requests
---------------------------------

GET the google.com homepage
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Example of how to send a GET request::

    <?php

    use Guzzle\Http\Message\RequestFactory;

    $request = RequestFactory::get('http://www.google.com/');
    $response = $request->send();

    // The response is an object
    echo $response->getStatusCode() . "\n";
    // Cast the request to a string to see the raw HTTP request message
    echo $request;
    // Cast the response to a string to see the raw HTTP response message
    echo $response;

POST to a Solr server
~~~~~~~~~~~~~~~~~~~~~

Example of how to send a POST request::

    <?php

    // Use the factory (notice the @ symbol):
    $request = RequestFactory::post('http://localhost:8983/solr/update', null, array (
        'file' => '@/path/to/documents.xml'
    ));
    $response = $request->send();

    // Or, Add the POST files manually
    $request = RequestFactory::post('http://localhost:8983/solr/update')
        ->addPostFiles(array(
            'file' => '/path/to/documents.xml'
        ));
    $response = $request->send();

Send a request and retry using exponential backoff
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Here's an example of sending an HTTP request that will automatically retry transient failures using truncated exponential backoff::

    <?php
    use Guzzle\Http\Plugin\ExponentialBackoffPlugin;

    $request = RequestFactory::get('http://google.com/');
    $request->getEventManager()->attach(new ExponentialBackoffPlugin());
    $response = $request->send();