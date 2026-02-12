<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;
use Pulsar\Http\Message\Response;

/**
 * Early 204 response for known bots — saves processing resources.
 */
#[Internal(reason: 'Analytics middleware — bot filtering')]
final readonly class BotFilterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private BotDetector $botDetector,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userAgent = $request->getHeaderLine('User-Agent');
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = $values[0] ?? '';
        }

        if ($this->botDetector->isBot($userAgent, $headers)) {
            return Response::noContent();
        }

        return $handler->handle($request);
    }
}
