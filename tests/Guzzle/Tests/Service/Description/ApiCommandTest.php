<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Common\Collection;
use Guzzle\Service\Inspector;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Description\ApiParam;
use Guzzle\Service\Exception\ValidationException;

/**
 * @covers Guzzle\Service\Description\ApiCommand
 */
class ApiCommandTest extends \Guzzle\Tests\GuzzleTestCase
{
    public static function strtoupper($string)
    {
        return strtoupper($string);
    }

    public function testApiCommandIsDataObject()
    {
        $c = new ApiCommand(array(
            'name'    => 'test',
            'doc'     => 'doc',
            'doc_url' => 'http://www.example.com',
            'method'  => 'POST',
            'uri'     => '/api/v1',
            'result_type' => 'array',
            'result_doc'  => 'returns the json_decoded response',
            'deprecated'  => true,
            'params' => array(
                'key' => array(
                    'required'   => true,
                    'type'       => 'string',
                    'max_length' => 10
                ),
                'key_2' => array(
                    'required' => true,
                    'type'     => 'integer',
                    'default'  => 10
                )
           )
        ));

        $this->assertEquals('test', $c->getName());
        $this->assertEquals('doc', $c->getDoc());
        $this->assertEquals('http://www.example.com', $c->getDocUrl());
        $this->assertEquals('POST', $c->getMethod());
        $this->assertEquals('/api/v1', $c->getUri());
        $this->assertEquals('array', $c->getResultType());
        $this->assertEquals('returns the json_decoded response', $c->getResultDoc());
        $this->assertTrue($c->isDeprecated());
        $this->assertEquals('Guzzle\\Service\\Command\\DynamicCommand', $c->getConcreteClass());
        $this->assertEquals(array(
            'key' => new ApiParam(array(
                'name' => 'key',
                'required' => true,
                'type' => 'string',
                'max_length' => 10
            )),
            'key_2' => new ApiParam(array(
                'name' => 'key_2',
                'required' => true,
                'type' => 'integer',
                'default' => 10
            ))
        ), $c->getParams());

        $this->assertEquals(new ApiParam(array(
            'name' => 'key_2',
            'required' => true,
            'type' => 'integer',
            'default' => 10
        )), $c->getParam('key_2'));

        $this->assertNull($c->getParam('afefwef'));
    }

    public function testAllowsConcreteCommands()
    {
        $c = new ApiCommand(array(
            'name' => 'test',
            'class' => 'Guzzle\\Service\\Command\ClosureCommand',
            'params' => array(
                'p' => new ApiParam(array(
                    'name' => 'foo'
                ))
            )
        ));
        $this->assertEquals('Guzzle\\Service\\Command\ClosureCommand', $c->getConcreteClass());
    }

    public function testConvertsToArray()
    {
        $data = array(
            'name'      => 'test',
            'class'     => 'Guzzle\\Service\\Command\ClosureCommand',
            'doc'       => 'test',
            'doc_url'   => 'http://www.example.com',
            'method'    => 'PUT',
            'uri'       => '/',
            'params'    => array(
                'p' => array(
                    'name' => 'foo'
                )
            ),
            'result_type' => null,
            'result_doc'  => null,
            'deprecated'  => false
        );
        $c = new ApiCommand($data);
        $toArray = $c->toArray();
        $this->assertArrayHasKey('params', $toArray);
        $this->assertInternalType('array', $toArray['params']);

        // Normalize the array
        unset($data['params']);
        unset($toArray['params']);
        $this->assertEquals($data, $toArray);
    }

    public function testAddsDefaultAndInjectsConfigs()
    {
        $col = new Collection(array(
            'username' => 'user',
            'string'   => 'test',
            'float'    => 1.23
        ));

        $this->getApiCommand()->validate($col);
        $this->assertEquals(false, $col->get('bool_2'));
        $this->assertEquals('user_test_', $col->get('dynamic'));
        $this->assertEquals(1.23, $col->get('float'));
    }

    /**
     * @expectedException Guzzle\Service\Exception\ValidationException
     */
    public function testValidatesTypeHints()
    {
        $this->getApiCommand()->validate(new Collection(array(
            'test' => 'uh oh',
            'username' => 'test'
        )));
    }

    public function testConvertsBooleanDefaults()
    {
        $c = new Collection(array(
            'test' => $this,
            'username' => 'test'
        ));

        $this->getApiCommand()->validate($c);
        $this->assertTrue($c->get('bool_1'));
        $this->assertFalse($c->get('bool_2'));
    }

    public function testValidatesArgs()
    {
        $config = new Collection(array(
            'data' => 123,
            'min'  => 'a',
            'max'  => 'aaa'
        ));

        $command = new ApiCommand(array(
            'params' => array(
                'data' => new ApiParam(array(
                    'name' => 'data',
                    'type' => 'string'
                )),
                'min' => new ApiParam(array(
                    'name' => 'min',
                    'type' => 'string',
                    'min_length' => 2
                )),
                'max' => new ApiParam(array(
                    'name' => 'max',
                    'type' => 'string',
                    'max_length' => 2
                ))
            )
        ));

        try {
            $command->validate($config);
            $this->fail('Did not throw expected exception');
        } catch (ValidationException $e) {
            $concat = implode("\n", $e->getErrors());
            $this->assertContains("Value must be of type string", $concat);
            $this->assertContains("[min] Length must be >= 2", $concat);
            $this->assertContains("[max] Length must be <= 2", $concat);
        }
    }

    public function testRunsValuesThroughFilters()
    {
        $data = new Collection(array(
            'username'      => 'TEST',
            'test_function' => 'foo'
        ));

        $this->getApiCommand()->validate($data);
        $this->assertEquals('test', $data->get('username'));
        $this->assertEquals('FOO', $data->get('test_function'));
    }

    public function testTypeValidationCanBeDisabled()
    {
        $i = Inspector::getInstance();
        $i->setTypeValidation(false);

        $command = $this->getTestCommand();
        $command->validate(new Collection(array(
            'data' => new \stdClass()
        )), $i);
    }

    public function testSkipsFurtherValidationIfNotSet()
    {
        $command = $this->getTestCommand();
        $command->validate(new Collection());
    }

    /**
     * @expectedException Guzzle\Service\Exception\ValidationException
     * @expectedExceptionMessage Validation errors: [data] is required.
     */
    public function testValidatesRequiredFieldsAreSet()
    {
        $command = new ApiCommand(array(
            'params' => array(
                'data' => new ApiParam(array(
                    'name'     => 'data',
                    'required' => true
                ))
            )
        ));

        $command->validate(new Collection());
    }

    public function testDeterminesIfHasParam()
    {
        $command = $this->getTestCommand();
        $this->assertTrue($command->hasParam('data'));
        $this->assertFalse($command->hasParam('baz'));
    }

    public function testReturnsParamNames()
    {
        $command = $this->getTestCommand();
        $this->assertEquals(array('data'), $command->getParamNames());
    }

    protected function getTestCommand()
    {
        return new ApiCommand(array(
            'params' => array(
                'data' => new ApiParam(array(
                    'type' => 'string'
                ))
            )
        ));
    }

    public function testCanBuildUpCommands()
    {
        $c = new ApiCommand(array());
        $c->setName('foo')
            ->setConcreteClass('Baz')
            ->setDeprecated(false)
            ->setDoc('doc')
            ->setDocUrl('http://www.foo.com')
            ->setMethod('PUT')
            ->setResultDoc('oh')
            ->setResultType('string')
            ->setUri('/foo/bar')
            ->addParam(new ApiParam(array(
                'name' => 'test'
            )));

        $this->assertEquals('foo', $c->getName());
        $this->assertEquals('Baz', $c->getConcreteClass());
        $this->assertEquals(false, $c->isDeprecated());
        $this->assertEquals('doc', $c->getDoc());
        $this->assertEquals('http://www.foo.com', $c->getDocUrl());
        $this->assertEquals('PUT', $c->getMethod());
        $this->assertEquals('oh', $c->getResultDoc());
        $this->assertEquals('string', $c->getResultType());
        $this->assertEquals('/foo/bar', $c->getUri());
        $this->assertEquals(array('test'), $c->getParamNames());
    }

    public function testCanRemoveParams()
    {
        $c = new ApiCommand(array());
        $c->addParam(new ApiParam(array('name' => 'foo')));
        $this->assertTrue($c->hasParam('foo'));
        $c->removeParam('foo');
        $this->assertFalse($c->hasParam('foo'));
    }

    public function testRecursivelyValidatesAndFormatsInput()
    {
        $command = new ApiCommand(array(
            'params' => array(
                'foo' => new ApiParam(array(
                    'name'      => 'foo',
                    'type'      => 'array',
                    'location'  => 'query',
                    'required'  => true,
                    'structure' => array(
                        array(
                            'name'      => 'baz',
                            'type'      => 'array',
                            'required'  => true,
                            'structure' => array(
                                array(
                                    'name'    => 'bam',
                                    'type'    => 'bool',
                                    'default' => true
                                ),
                                array(
                                    'name'     => 'boo',
                                    'type'     => 'string',
                                    'filters'  => 'strtoupper',
                                    'defaut'   => 'mesa'
                                )
                            )
                        ),
                        array(
                            'name'    => 'bar',
                            'default' => '123'
                        )
                    )
                ))
            )
        ));

        $input = new Collection(array());
        $command->validate($input);
        $this->assertEquals(array(
            'foo' => array(
                'baz' => array(
                    'bam' => true
                ),
                'bar' => '123'
            )
        ), $input->getAll());
    }

    public function testAddsNameToApiParamsIfNeeded()
    {
        $command = new ApiCommand(array('params' => array('foo' => new ApiParam(array()))));
        $this->assertEquals('foo', $command->getParam('foo')->getName());
    }

    /**
     * @return ApiCommand
     */
    protected function getApiCommand()
    {
        return new ApiCommand(array(
            'name' => 'ApiCommandTest',
            'class' => get_class($this),
            'params' => array(
                'test' => array(
                    'type' => 'type:object'
                ),
                'bool_1' => array(
                    'default' => true,
                    'type'    => 'boolean'
                ),
                'bool_2' => array('default' => false),
                'float' => array('type' => 'float'),
                'int' => array('type' => 'integer'),
                'date' => array('type' => 'date'),
                'timestamp' => array('type' => 'time'),
                'string' => array('type' => 'string'),
                'username' => array(
                    'required' => true,
                    'filters' => 'strtolower'
                ),
                'dynamic' => array(
                    'default' => '{username}_{ string }_{ does_not_exist }'
                ),
                'test_function' => array(
                    'type'    => 'string',
                    'filters' => __CLASS__ . '::strtoupper'
                )
            )
        ));
    }
}
