<?php
namespace GuzzleHttp\Exception;

use GuzzleHttp\Exception\Traits\HandlerContextAwareTrait;
use GuzzleHttp\Exception\Traits\RequestAwareTrait;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP Request exception
 */
class RequestException extends TransferException implements RequestExceptionInterface
{
    use RequestAwareTrait, HandlerContextAwareTrait;

    /** @var ResponseInterface|null */
    private $response;

    public function __construct(
        string $message,
        RequestInterface $request,
        ResponseInterface $response = null,
        \Throwable $previous = null,
        array $handlerContext = []
    ) {
        // Set the code of the exception if the response is set and not future.
        $code = $response ? $response->getStatusCode() : 0;
        parent::__construct($message, $code, $previous);
        $this->setRequest($request);
        $this->response = $response;
        $this->setHandlerContext($handlerContext);
    }

    /**
     * Wrap non-RequestExceptions with a RequestException
     */
    public static function wrapException(RequestInterface $request, \Throwable $e): RequestException
    {
        return $e instanceof RequestException
            ? $e
            : new RequestException($e->getMessage(), $request, null, $e);
    }

    /**
     * Factory method to create a new exception with a normalized error message
     *
     * @param RequestInterface  $request  Request
     * @param ResponseInterface $response Response received
     * @param \Throwable        $previous Previous exception
     * @param array             $ctx      Optional handler context.
     */
    public static function create(
        RequestInterface $request,
        ResponseInterface $response = null,
        \Throwable $previous = null,
        array $ctx = []
    ): self {
        if (!$response) {
            return new self(
                'Error completing request',
                $request,
                null,
                $previous,
                $ctx
            );
        }

        $level = (int) \floor($response->getStatusCode() / 100);
        if ($level === 4) {
            $label = 'Client error';
            $className = ClientException::class;
        } elseif ($level === 5) {
            $label = 'Server error';
            $className = ServerException::class;
        } else {
            $label = 'Unsuccessful request';
            $className = __CLASS__;
        }

        $uri = $request->getUri();
        $uri = static::obfuscateUri($uri);

        // Client Error: `GET /` resulted in a `404 Not Found` response:
        // <html> ... (truncated)
        $message = \sprintf(
            '%s: `%s %s` resulted in a `%s %s` response',
            $label,
            $request->getMethod(),
            $uri,
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );

        $summary = \GuzzleHttp\Psr7\get_message_body_summary($response);

        if ($summary !== null) {
            $message .= ":\n{$summary}\n";
        }

        return new $className($message, $request, $response, $previous, $ctx);
    }

    /**
     * Obfuscates URI if there is a username and a password present
     */
    private static function obfuscateUri(UriInterface $uri): UriInterface
    {
        $userInfo = $uri->getUserInfo();

        if (false !== ($pos = \strpos($userInfo, ':'))) {
            return $uri->withUserInfo(\substr($userInfo, 0, $pos), '***');
        }

        return $uri;
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
