<?php
namespace GuzzleHttp\Exception\Traits;

use Psr\Http\Message\ResponseInterface;

trait ResponseAwareTrait
{
    /** @var ResponseInterface|null */
    private $response;

    private function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /**
     * Get the associated response
     */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * Check if a response was received
     */
    public function hasResponse(): bool
    {
        return $this->response !== null;
    }
}
