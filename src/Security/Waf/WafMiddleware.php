<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * HTTP middleware that applies WAF rule evaluation to incoming requests.
 *
 * Blocks requests that match critical rules and logs all matches.
 */
#[Api(since: '1.0.0')]
final readonly class WafMiddleware implements MiddlewareInterface
{
    public function __construct(
        private WafEngine $engine,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $matches = $this->engine->evaluate($request);

        if ($matches !== []) {
            foreach ($matches as $match) {
                $this->logger->warning('WAF rule matched', [
                    'rule_id' => $match->rule->id,
                    'message' => $match->rule->message,
                    'target' => $match->matchedTarget->value,
                    'severity' => $match->rule->severity->name,
                    'action' => $match->rule->action->value,
                    'client_ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
                    'uri' => (string) $request->getUri(),
                ]);
            }

            if ($this->engine->shouldBlock($matches)) {
                return $this->responseFactory->createResponse(403, 'Forbidden');
            }
        }

        return $handler->handle($request);
    }
}
