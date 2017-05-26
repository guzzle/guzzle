<?php
namespace GuzzleHttp\Subscriber;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Stores responses in and delivers from local fixture files
 *
 * - The name of a fixture file is an MD5 hash of the complete request body.
 * - Mocked responses are not stored
 * - If a fixture file is not already present, an actual request is being sent once,
 *   subsequent requests will be served
 */
class Fixture implements SubscriberInterface
{
    /** @var string Local fixtures storage path */
    protected $path;

    /** @var MessageFactory */
    protected $factory;

    /**
     * @param string $fixturesPath Location to store the fixtures in
     */
    public function __construct($fixturesPath)
    {
        $this->factory = new MessageFactory();
        $this->initFixturesPath($fixturesPath);
    }

    /**
     * Create the given fixtures path, if not already there
     *
     * @param string $path
     */
    protected function initFixturesPath($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $this->path = $path;
    }

    public function getEvents()
    {
        return [
            'before' => ['onBefore', RequestEvents::SIGN_REQUEST - 20],
            'complete' => ['onComplete', RequestEvents::EARLY],
        ];
    }

    public function onBefore(BeforeEvent $event)
    {
        if ($response = $this->getResponseFromFixture($event->getRequest())) {
            $event->intercept($response);
        }
    }

    public function onComplete(CompleteEvent $event)
    {
        if ($this->clientHasMockSubscriber($event->getClient())) {
            // Don't store already mocked responses
            return;
        }

        $this->createFixture($event->getRequest(), $event->getResponse());
    }

    /**
     * Returns true if the client has a mock subscriber attached
     *
     * @param ClientInterface $client
     * @return bool
     */
    protected function clientHasMockSubscriber(ClientInterface $client)
    {
        $beforeListeners = $client->getEmitter()->listeners('before');
        foreach( $beforeListeners as $listener) {
            if ($listener[0] instanceof Mock) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param RequestInterface $request
     * @return \GuzzleHttp\Message\ResponseInterface|null
     */
    protected function getResponseFromFixture(RequestInterface $request)
    {
        $hash = md5((string) $request);
        $file = $this->path . DIRECTORY_SEPARATOR . $hash . '.fixture';

        return file_exists($file)
            ? $this->factory->fromMessage(file_get_contents($file))
            : null;
    }

    /**
     * Writes a response string to a file named after the request
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool true on success, false on failure
     */
    protected function createFixture(RequestInterface $request, ResponseInterface $response)
    {
        $hash = md5((string) $request);
        $file = $this->path . DIRECTORY_SEPARATOR . $hash . '.fixture';
        $responseString = (string) $response;

        return file_put_contents($file, $responseString) !== false;
    }
} 