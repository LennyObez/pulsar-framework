<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Http3\EarlyHintsInterface;
use Pulsar\Http\Method;

/**
 * Emits 103 Early Hints for the application shell before the handler runs.
 *
 * The whole value of a 103 is that it reaches the browser while the server is still
 * working, so the hints have to be decided from the request alone — anything that
 * needs the handler's result arrives too late to preload anything. That is why this
 * takes a prepared {@see EarlyHintsInterface} set rather than collecting hints from
 * the response: by then the response is what the browser was waiting for.
 *
 * Runs outermost so the hints go out before any other middleware spends time.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class EarlyHintsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private EarlyHintsInterface $hints,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A hint is an instruction to fetch a subresource, which only a document
        // request can act on. Sending them on a POST, or on a HEAD that will carry no
        // body, spends bytes on a client that has nothing to preload for.
        if ($this->shouldHint($request)) {
            $this->hints->send();
        }

        return $handler->handle($request);
    }

    private function shouldHint(ServerRequestInterface $request): bool
    {
        if ($this->hints->isEmpty()) {
            return false;
        }

        if ($request->getMethod() !== Method::GET->value) {
            return false;
        }

        // Hints describe a document's subresources, so only a document navigation can
        // act on them. `Sec-Fetch-Dest: document` marks exactly that; `empty` is a
        // fetch()/XHR call, which renders nothing and would preload for no one. An
        // absent header means a client too old to tell us, and those are served hints
        // rather than penalised for their age.
        $destination = $request->getHeaderLine('Sec-Fetch-Dest');

        return $destination === '' || $destination === 'document';
    }
}
