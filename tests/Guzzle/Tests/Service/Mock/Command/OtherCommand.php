<?php

namespace Guzzle\Tests\Service\Mock\Command;

/**
 * Other mock Command
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle test default="123" required="true" doc="Test argument"
 * @guzzle other
 * @guzzle arg type="string
 * guzzle static static="this is static"
 */
class OtherCommand extends MockCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('HEAD');
    }
}