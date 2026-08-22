<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Internal\Security\PciDssCompliance;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function in_array;
use function strtoupper;

/**
 * Payment security middleware.
 *
 * Enforces PCI-DSS compliance by scanning request bodies for raw card
 * numbers. Any request carrying a PAN is rejected before reaching the
 * payment controller.
 *
 * Piped onto the client-facing payments routes by
 * `PaymentsExtension::boot()`; gateway webhook routes are exempt, and that
 * exemption is explained there.
 */
#[Internal]
final readonly class PaymentSecurityMiddleware implements MiddlewareInterface
{
    /**
     * Methods whose bodies are screened. No payments handler reads a GET or
     * DELETE body, so scanning one would only cost time.
     */
    private const array BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::BODY_METHODS, true)) {
            return $handler->handle($request);
        }

        /** @var array<array-key, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            PciDssCompliance::assertNoPan($body);
        } catch (PaymentException $e) {
            return Response::json([
                'error' => 'PCI-DSS compliance violation',
                'detail' => $e->getMessage(),
            ], 400);
        }

        return $handler->handle($request);
    }
}
