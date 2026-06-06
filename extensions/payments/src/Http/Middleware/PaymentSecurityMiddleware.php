<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Internal\Security\PciDssCompliance;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Throwable;

use function in_array;

/**
 * Payment security middleware.
 *
 * Enforces PCI-DSS compliance by scanning request bodies for
 * raw card numbers. Any request containing a PAN is rejected
 * before reaching the payment controller.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class PaymentSecurityMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only check POST/PUT/PATCH requests with bodies
        $method = strtoupper($request->getMethod());

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            /** @var array<string, mixed> $body */
            $body = (array) ($request->getParsedBody() ?? []);

            try {
                PciDssCompliance::assertNoPan($body);
            } catch (Throwable $e) {
                return Response::json([
                    'error' => 'PCI-DSS compliance violation',
                    'detail' => $e->getMessage(),
                ], 400);
            }
        }

        return $handler->handle($request);
    }
}
