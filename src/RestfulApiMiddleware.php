<?php
namespace GuzzleHttp;


use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;


/**
 * Pre-process URIs which contains attributes with values provided by $options['attributes']
 *
 * Apply this middleware like other middleware using
 * {@see GuzzleHttp\Middleware::redirect()}.
 */
class RestfulApiMiddleware
{

    private $nextHandler;

    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        $fn = $this->nextHandler;

        // Don't do any thing if attributes is not set
        if (!isset($options['attributes'])) {
            return $fn($request, $options);
        }

        $attributes = $options['attributes'];
        $uri = (string)$request->getUri();

        $translatedUri = $this->interpolate($uri, $attributes);

        $request = $request->withUri(new Uri($translatedUri), true);

        return $fn($request, $options);
    }

    /**
     * Translate placeholders with context.
     *
     * @param $message
     * @param array $context
     * @return string
     */
    private function interpolate($message, array $context = [])
    {
        $placeholders = [];

        foreach ($context as $key => $val) {
            if (! is_array($val) && (! is_object($val) || method_exists($val, '__toString'))) {
                $placeholders['{' . $key . '}'] = $val;
            }
        }

        return strtr(urldecode($message), $placeholders);
    }
}