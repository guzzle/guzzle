<?php

namespace Guzzle\Cache;

class MemoryCacheAdapter implements CacheAdapterInterface
{
    private $_cache;
    
    public function fetch($id, array $options = null) {
        
        // no cache found
        if(!isset($this->_cache[$id])) {
            return false;
        }
        
        // cache found and expiration not reached
        if(!$this->_cache[$id]['expire'] || time() < $this->_cache[$id]['expire']) {
            return $this->_cache[$id]['value'];
        }
        
        // expiration reached - return false
        unset($this->_cache[$id]);
        return false;
    }
    
    public function save($id, $data, $lifeTime = false, array $options = null) {
        
        $secondsInMonth = 30 * 24 * 60 * 60;
        if($lifeTime && $lifeTime < $secondsInMonth) {
            $lifeTime = time() + $lifeTime;
        }
        
        $this->_cache[$id] = array('expire' => $lifeTime, 'value' => $data);
        return $this;
    }
    
    public function contains($id, array $options = null) {
        return isset($this->_cache[$id]);
    }
    
    public function delete($id, array $options = null) {
        unset($this->_cache[$id]);
    }
}
