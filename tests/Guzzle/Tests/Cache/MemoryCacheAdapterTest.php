<?php

namespace Guzzle\Tests\Cache;

use Guzzle\Cache\MemoryCacheAdapter;

class MemoryCacheAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    private $_cache = array();
    
    public function setUp() {
        $this->_cache = new MemoryCacheAdapter;
    }
    
    public function testSetWithoutExpiration()
    {
        $this->_cache->save('var', 'val');
        $this->assertEquals('val', $this->_cache->fetch('var'));
    }
    
    public function testSetWithExpiration()
    {
        $this->_cache->save('var', 'val', 10);
        $this->assertEquals('val', $this->_cache->fetch('var'));
    }
    
    public function testGetUnexisted()
    {
        $this->assertFalse($this->_cache->fetch('var'));
    }
    
    public function testGetExpired()
    {
        $this->_cache->save('var', 'val', time() - 1000);
        $this->assertFalse($this->_cache->fetch('var'));
    }
    
    public function testDelete()
    {
        $this->_cache->save('var', 'val');
        $this->_cache->delete('var');
        $this->assertFalse($this->_cache->fetch('var'));
    }
    
    public function testContains()
    {
        $this->_cache->save('var', 'val');
        $this->assertTrue($this->_cache->contains('var'));
        $this->assertFalse($this->_cache->contains('invalid-var'));
    }
}