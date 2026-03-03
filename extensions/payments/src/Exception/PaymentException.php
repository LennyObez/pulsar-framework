<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Exception;

use NoDiscard;
use RuntimeException;

use function sprintf;

/**
 * Domain-level payment exceptions.
 */
final class PaymentException extends RuntimeException
{
    #[NoDiscard]
    public static function invalidTransition(string $entity, string $from, string $to): self
    {
        return new self(sprintf(
            'Invalid %s transition from "%s" to "%s"',
            $entity,
            $from,
            $to,
        ));
    }

    #[NoDiscard]
    public static function notFound(string $entity, string $id): self
    {
        return new self(sprintf('%s not found: %s', $entity, $id));
    }

    #[NoDiscard]
    public static function invalid(string $message): self
    {
        return new self($message);
    }

    #[NoDiscard]
    public static function jwsVerificationFailed(string $reason): self
    {
        return new self(sprintf('JWS signature verification failed: %s', $reason));
    }

    /**
     * F13.10: refused payment operation because the deployment is
     * configured for `requireTenantContext = true` but no `TenantContext`
     * is currently resolved (the request pipeline did not enter a tenant
     * scope, or the gateway was invoked from a CLI / test path that
     * forgot to set one). Failing closed prevents an accidental cross-
     * tenant payment issuance under multi-tenant deployment.
     */
    #[NoDiscard]
    public static function missingTenantContext(string $operation): self
    {
        return new self(sprintf(
            'Payment operation "%s" was invoked without an active TenantContext. '
            . 'PaymentsConfig::requireTenantContext is enabled — every payment must run inside a tenant scope. '
            . 'Either set the active tenant before calling the gateway or disable require_tenant_context in config/payments.php.',
            $operation,
        ));
    }
}
