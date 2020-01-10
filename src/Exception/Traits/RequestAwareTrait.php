<?php
namespace GuzzleHttp\Exception\Traits;

use Psr\Http\Message\RequestInterface;

trait RequestAwareTrait
{
    /** @var RequestInterface */
    private $request;

    private function setRequest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Get the request that caused the exception
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
