<?php

namespace Guzzle\Http\Message;

/**
 * Default response processor that throws Exceptions when a non-2xx response
 * is received.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DefaultResponseProcessor implements ResponseProcessorInterface
{
    /**
     * {@inheritdoc}
     *
     * @throws BadResponseException if the response is not successful
     */
    public function processResponse(RequestInterface $request, Response $response)
    {
        // Throw an exception if the request was not successful
        if ($response->isClientError() || $response->isServerError()) {

            $messageParts = array(
                '[status code] ' . $response->getStatusCode(),
                '[reason phrase] ' . $response->getReasonPhrase(),
                '[url] ' . $request->getUrl(),
                '[request] ' . (string) $request,
                '[response] ' . (string) $response
            );

            $e = new BadResponseException('Unsuccessful response | ' . implode(' | ', array_filter($messageParts, function($message) {
                return preg_match('/\[[A-Za-z0-9 ]+\]\s.+/', $message);
            })));

            $e->setResponse($response);
            $e->setRequest($request);

            $request->getSubjectMediator()->notify('request.failure', $e);
            
            throw $e;
        }

        $request->getSubjectMediator()->notify('request.success', $response);
    }
}