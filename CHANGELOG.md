CHANGELOG
=========

* 2.6.6 (06-10-2012)

 * BC: Removing Guzzle\Http\Plugin\BatchQueuePlugin
 * BC: Removing Guzzle\Service\Command\CommandSet
 * Adding generic batching system (replaces the batch queue plugin and command set)
 * Updating ZF cache and log adapters and now using ZF's composer repository
 * Bug: Setting the name of each ApiParam when creating through an ApiCommand
 * Adding result_type, result_doc, deprecated, and doc_url to service descriptions
 * Bug: Changed the default cookie header casing back to 'Cookie'

* 2.6.5 (06-03-2012)

 * BC: Renaming Guzzle\Http\Message\RequestInterface::getResourceUri() to getResource()
 * BC: Removing unused AUTH_BASIC and AUTH_DIGEST constants from
 * BC: Guzzle\Http\Cookie is now used to manage Set-Cookie data, not Cookie data
 * BC: Renaming methods in the CookieJarInterface
 * Moving almost all cookie logic out of the CookiePlugin and into the Cookie or CookieJar implementations
 * Making the default glue for HTTP headers ';' instead of ','
 * Adding a removeValue to Guzzle\Http\Message\Header
 * Adding getCookies() to request interface.
 * Making it easier to add event subscribers to HasDispatcherInterface classes. Can now directly call addSubscriber()

* 2.6.4 (05-30-2012)

 * BC: Cleaning up how POST files are stored in EntityEnclosingRequest objects. Adding PostFile class.
 * BC: Moving ApiCommand specific functionality from the Inspector and on to the ApiCommand
 * Bug: Fixing magic method command calls on clients
 * Bug: Email constraint only validates strings
 * Bug: Aggregate POST fields when POST files are present in curl handle
 * Bug: Fixing default User-Agent header
 * Bug: Only appending or prepending parameters in commands if they are specified
 * Bug: Not requiring response reason phrases or status codes to match a predefined list of codes
 * Allowing the use of dot notation for class namespaces when using instance_of constraint
 * Added any_match validation constraint
 * Added an AsyncPlugin
 * Passing request object to the calculateWait method of the ExponentialBackoffPlugin
 * Allowing the result of a command object to be changed
 * Parsing location and type sub values when instantiating a service description rather than over and over at runtime

* 2.6.3 (05-23-2012)

 * [BC] Guzzle\Common\FromConfigInterface no longer requires any config options.
 * [BC] Refactoring how POST files are stored on an EntityEnclosingRequest. They are now separate from POST fields.
 * You can now use an array of data when creating PUT request bodies in the request factory.
 * Removing the requirement that HTTPS requests needed a Cache-Control: public directive to be cacheable.
 * [Http] Adding support for Content-Type in multipart POST uploads per upload
 * [Http] Added support for uploading multiple files using the same name (foo[0], foo[1])
 * Adding more POST data operations for easier manipulation of POST data.
 * You can now set empty POST fields.
 * The body of a request is only shown on EntityEnclosingRequest objects that do not use POST files.
 * Split the Guzzle\Service\Inspector::validateConfig method into two methods. One to initialize when a command is created, and one to validate.
 * CS updates

* 2.6.2 (05-19-2012)

 * [Http] Better handling of nested scope requests in CurlMulti.  Requests are now always prepares in the send() method rather than the addRequest() method.

* 2.6.1 (05-19-2012)

 * [BC] Removing 'path' support in service descriptions.  Use 'uri'.
 * [BC] Guzzle\Service\Inspector::parseDocBlock is now protected. Adding getApiParamsForClass() with cache.
 * [BC] Removing Guzzle\Common\NullObject.  Use https://github.com/mtdowling/NullObject if you need it.
 * [BC] Removing Guzzle\Common\XmlElement.
 * All commands, both dynamic and concrete, have ApiCommand objects.
 * Adding a fix for CurlMulti so that if all of the connections encounter some sort of curl error, then the loop exits.
 * Adding checks to EntityEnclosingRequest so that empty POST files and fields are ignored.
 * Making the method signature of Guzzle\Service\Builder\ServiceBuilder::factory more flexible.

* 2.6.0 (05-15-2012)

 * [BC] Moving Guzzle\Service\Builder to Guzzle\Service\Builder\ServiceBuilder
 * [BC] Executing a Command returns the result of the command rather than the command
 * [BC] Moving all HTTP parsing logic to Guzzle\Http\Parsers. Allows for faster C implementations if needed.
 * [BC] Changing the Guzzle\Http\Message\Response::setProtocol() method to accept a protocol and version in separate args.
 * [BC] Moving ResourceIterator* to Guzzle\Service\Resource
 * [BC] Completely refactored ResourceIterators to iterate over a cloned command object
 * [BC] Moved Guzzle\Http\UriTemplate to Guzzle\Http\Parser\UriTemplate\UriTemplate
 * [BC] Guzzle\Guzzle is now deprecated
 * Moving Guzzle\Common\Guzzle::inject to Guzzle\Common\Collection::inject
 * Adding Guzzle\Version class to give version information about Guzzle
 * Adding Guzzle\Http\Utils class to provide getDefaultUserAgent() and getHttpDate()
 * Adding Guzzle\Curl\CurlVersion to manage caching curl_version() data
 * ServiceDescription and ServiceBuilder are now cacheable using similar configs
 * Changing the format of XML and JSON service builder configs.  Backwards compatible.
 * Cleaned up Cookie parsing
 * Trimming the default Guzzle User-Agent header
 * Adding a setOnComplete() method to Commands that is called when a command completes
 * Keeping track of requests that were mocked in the MockPlugin
 * Fixed a caching bug in the CacheAdapterFactory
 * Inspector objects can be injected into a Command object
 * Refactoring a lot of code and tests to be case insensitive when dealing with headers
 * Adding Guzzle\Http\Message\HeaderComparison for easy comparison of HTTP headers using a DSL
 * Adding the ability to set global option overrides to service builder configs
 * Adding the ability to include other service builder config files from within XML and JSON files
 * Moving the parseQuery method out of Url and on to QueryString::fromString() as a static factory method.

* 2.5.0 (05-08-2012)

 * Major performance improvements
 * [BC] Simplifying Guzzle\Common\Collection.  Please check to see if you are using features that are now deprecated.
 * [BC] Using a custom validation system that allows a flyweight implementation for much faster validation. No longer using Symfony2 Validation component.
 * [BC] No longer supporting "{{ }}" for injecting into command or UriTemplates.  Use "{}"
 * Added the ability to passed parameters to all requests created by a client
 * Added callback functionality to the ExponentialBackoffPlugin
 * Using microtime in ExponentialBackoffPlugin to allow more granular backoff stategies.
 * Rewinding request stream bodies when retrying requests
 * Exception is thrown when JSON response body cannot be decoded
 * Added configurable magic method calls to clients and commands.  This is off by default.
 * Fixed a defect that added a hash to every parsed URL part
 * Fixed duplicate none generation for OauthPlugin.
 * Emitting an event each time a client is generated by a ServiceBuilder
 * Using an ApiParams object instead of a Collection for parameters of an ApiCommand
 * cache.* request parameters should be renamed to params.cache.*
 * Added the ability to set arbitrary curl options on requests (disable_wire, progress, etc). See CurlHandle.
 * Added the ability to disable type validation of service descriptions
 * ServiceDescriptions and ServiceBuilders are now Serializable
