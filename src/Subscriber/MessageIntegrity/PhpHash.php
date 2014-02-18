<?php

namespace GuzzleHttp\Subscriber\MessageIntegrity;

/**
 * Incremental hashing using PHP's hash functions
 */
class PhpHash implements HashInterface
{
    private $context;
    private $algo;
    private $options;

    /**
     * @param string $algo    Hashing algorithm. One of PHP's hash_algos() return values (e.g. md5)
     * @param array  $options Associative array of hashing options:
     *     - hmac_key: Shared secret key used with HMAC algorithms
     */
    public function __construct($algo, array $options = [])
    {
        $this->algo = $algo;
        $this->options = $options;
    }

    public function update($data)
    {
        hash_update($this->getContext(), $data);
    }

    public function complete()
    {
        return hash_final($this->getContext(), true);
    }

    /**
     * Create a hash context
     */
    protected function createContext()
    {
        if (isset($this->options['hmac_key'])) {
            $this->context = hash_init($this->algo, HASH_HMAC, $this->options['hmac_key']);
        } else {
            $this->context = hash_init($this->algo);
        }
    }

    /**
     * Get a hash context or create one if needed
     *
     * @return resource
     */
    private function getContext()
    {
        if (!$this->context) {
            $this->createContext();
        }

        return $this->context;
    }
}
