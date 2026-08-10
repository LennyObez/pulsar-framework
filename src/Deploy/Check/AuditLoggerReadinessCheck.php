<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Container\ContainerInterface;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

/**
 * Validates that a real (non-null) audit logger is wired in staging/production.
 *
 * NullAuditLogger silently discards every audit event. It is a
 * legitimate dev/test fallback so that security primitives such as
 * SafeHtmlPolicy can function without a full audit chain, but a production
 * deployment configured to resolve AuditLoggerInterface to NullAuditLogger
 * loses every compliance signal — PCI Req 10, HIPAA §164.312(b), SOX ITGC
 * change-trail, GDPR Art 30 — without any visible failure. The deploy gate
 * refuses it explicitly so the operator must make the deployment trail real.
 */
#[Internal]
final readonly class AuditLoggerReadinessCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'audit-logger';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates a non-null audit logger is wired in staging/production';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if ($environment === 'local') {
            return CheckResult::pass(
                self::CHECK_NAME,
                'Audit logger check is skipped in local environment',
            );
        }

        if (!$this->container->has(AuditLoggerInterface::class)) {
            return CheckResult::error(
                self::CHECK_NAME,
                "No AuditLoggerInterface binding registered for $environment",
                [
                    'Wire a real audit logger (AuditLogger with HMAC chain, file or DB sink)',
                    'in your composition root before deploying to staging or production.',
                ],
            );
        }

        $instance = $this->container->get(AuditLoggerInterface::class);

        if ($instance instanceof NullAuditLogger) {
            return CheckResult::error(
                self::CHECK_NAME,
                "NullAuditLogger is wired in $environment",
                [
                    'NullAuditLogger discards every audit event and is intended for tests only.',
                    'Bind AuditLoggerInterface to a real implementation (with HMAC chain + persistent sink)',
                    'before deploying. Compliance frameworks (PCI 10, HIPAA §164.312(b), SOX ITGC) require',
                    'an immutable audit trail.',
                ],
            );
        }

        return CheckResult::pass(
            self::CHECK_NAME,
            'AuditLoggerInterface is wired to a non-null implementation',
        );
    }
}
