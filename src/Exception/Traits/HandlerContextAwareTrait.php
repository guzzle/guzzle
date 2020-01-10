<?php
namespace GuzzleHttp\Exception\Traits;

trait HandlerContextAwareTrait
{
    /** @var array */
    private $handlerContext;

    private function setHandlerContext(array $handlerContext): void
    {
        $this->handlerContext = $handlerContext;
    }

    /**
     * Get contextual information about the error from the underlying handler.
     *
     * The contents of this array will vary depending on which handler you are
     * using. It may also be just an empty array. Relying on this data will
     * couple you to a specific handler, but can give more debug information
     * when needed.
     */
    public function getHandlerContext(): array
    {
        return $this->handlerContext;
    }
}
