<?php

namespace Guzzle\Tests\Http\Pool;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockPool extends \Guzzle\Http\Pool\Pool
{
    public function getHandle()
    {
        return $this->multiHandle;
    }
}