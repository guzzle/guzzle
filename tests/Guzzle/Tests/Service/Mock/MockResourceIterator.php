<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Mock;

use Guzzle\Service\ResourceIterator;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockResourceIterator extends ResourceIterator
{
    protected function sendRequest()
    {
        $request = $this->client->getRequest('GET');
        $request->getQuery()->set('count', $this->calculatePageSize());
        $data = json_decode($request->send()->getBody(true), true);

        $this->resourceList = $data['resources'];
        $this->nextToken = $data['next_token'];
        $this->retrievedCount += count($this->data['resources']);
        $this->currentIndex = 0;
    }
}