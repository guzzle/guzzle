<?php

namespace Guzzle\Plugin\Mock;

use Guzzle\Common\Event;
use Guzzle\Plugin\Mock\Exception\UnmatchedRequestException;
use Guzzle\Plugin\Mock\RequestMatcher\RequestMatcherInterface;
use Guzzle\Plugin\Mock\UnmatchedRequestStrategy\UnmatchedRequestStrategyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Match requests to mock responses.
 */
class MockMatcherPlugin implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return array(
            'request.before_send' => array('onRequestBeforeSend', -256),
        );
    }

    /**
     * Request matchers.
     *
     * @var RequestMatcherInterface[]
     */
    protected $matchers = array();

    /**
     * Unmatched request strategy.
     *
     * @var UnmatchedRequestStrategyInterface|null
     */
    protected $unmatchedRequestStrategy;

    /**
     * Constructor.
     *
     * @param RequestMatcherInterface[]              $matchers                 Request matchers
     * @param UnmatchedRequestStrategyInterface|null $unmatchedRequestStrategy Optional strategy to use when a request
     *                                                                         is not matched to a response
     */
    public function __construct(
        array $matchers = array(),
        UnmatchedRequestStrategyInterface $unmatchedRequestStrategy = null
    )
    {
        foreach ($matchers as $matcher) {
            $this->addMatcher($matcher);
        }

        $this->unmatchedRequestStrategy = $unmatchedRequestStrategy;
    }

    /**
     * Add a request matcher.
     *
     * @param RequestMatcherInterface $matcher Request Matcher
     *
     * @return self Reference to the plugin
     */
    public function addMatcher(RequestMatcherInterface $matcher)
    {
        $this->matchers[] = $matcher;

        return $this;
    }

    /**
     * Match a request to a response.
     *
     * @param Event $event Request before send event
     *
     * @throws UnmatchedRequestException If a request is not matched to a response and an unmatched request strategy is
     *                                   not set
     */
    public function onRequestBeforeSend(Event $event)
    {
        $request = $event['request'];

        if (null !== $request->getResponse()) {
            return;
        }

        foreach ($this->matchers as $matcher) {
            if (null !== $match = $matcher->match($request)) {
                $request->setResponse($match, true);

                return;
            }
        }

        if (null !== $this->unmatchedRequestStrategy) {
            $this->unmatchedRequestStrategy->handle($request);
        } else {
            throw new UnmatchedRequestException(sprintf(
                'No matcher found for the request %s %s',
                $request->getMethod(),
                $request->getUrl()
            ));
        }
    }
}
