<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\SessionInterface;

#[CoversClass(Api::class)]
final class SecurityApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function sessionInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(SessionInterface::class);
    }

    #[Test]
    public function csrfTokenManagerInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(CsrfTokenManagerInterface::class);
    }

    #[Test]
    public function auditSinkInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(AuditSinkInterface::class);
    }

    #[Test]
    public function auditEntryIsPublicApi(): void
    {
        self::assertHasApiAttribute(AuditEntry::class);
        self::assertClassIsReadonly(AuditEntry::class);
    }

    #[Test]
    public function auditEnumsArePublicApi(): void
    {
        self::assertHasApiAttribute(AuditEvent::class);
        self::assertHasApiAttribute(AuditOutcome::class);
    }

    #[Test]
    public function auditLoggerIsPublicApi(): void
    {
        self::assertHasApiAttribute(AuditLogger::class);
    }

    #[Test]
    public function securityExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(SecurityException::class);
    }

    #[Test]
    public function securityExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(SecurityException::class, 'masterKeyMissing');
    }
}
