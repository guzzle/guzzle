<?php

namespace Guzzle\Subscriber\MessageIntegrity;

/**
 * Interface that allows implementing various incremental hashes
 */
interface HashInterface
{
    /**
     * Add data to the
     *
     * @param $data
     */
    public function update($data);

    /**
     * Finalize an incremental hash and return resulting digest
     *
     * @return string
     */
    public function complete();
}
