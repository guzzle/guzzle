<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor;

use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Description\ApiParam;
use Guzzle\Tests\Service\Mock\Command\MockCommand;

abstract class AbstractVisitorTestCase extends \Guzzle\Tests\GuzzleTestCase
{
    protected $command;
    protected $request;

    public function setUp()
    {
        $this->command = new MockCommand();
        $this->request = new EntityEnclosingRequest('POST', 'http://www.test.com');
    }

    protected function getNestedCommand($location)
    {
        return new ApiCommand(array(
            'params' => array(
                'foo' => new ApiParam(array(
                    'type'         => 'array',
                    'location'     => $location,
                    'location_key' => 'Foo',
                    'required'     => true,
                    'structure'    => array(
                        'test' => array(
                            'type'      => 'array',
                            'required'  => true,
                            'structure' => array(
                                'baz' => array(
                                    'type'    => 'bool',
                                    'default' => true
                                ),
                                // Add a nested parameter that uses a different location_key than the input key
                                'jenga' => array(
                                    'type'         => 'string',
                                    'default'      => 'hello',
                                    'location_key' => 'Jenga_Yall!',
                                    'filters'      => array('strtoupper')
                                )
                            )
                        ),
                        'bar' => array('default' => 123)
                    )
                ))
            )
        ));
    }
}
