<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Pseudonymization\ForgetResult;

use function strlen;

#[CoversClass(ForgetResult::class)]
final class ForgetResultTest extends TestCase
{
    public function testConstructorAssignsProperties(): void
    {
        $forgottenAt = new DateTimeImmutable('2026-03-15T14:00:00+00:00');

        $result = new ForgetResult(
            confirmationHash: 'abc123def456',
            forgottenAt: $forgottenAt,
            auditEntryId: 'audit-789',
        );

        self::assertSame('abc123def456', $result->confirmationHash);
        self::assertSame($forgottenAt, $result->forgottenAt);
        self::assertSame('audit-789', $result->auditEntryId);
    }

    public function testToArrayContainsAllFields(): void
    {
        $result = new ForgetResult(
            confirmationHash: 'sha256-hash-value',
            forgottenAt: new DateTimeImmutable('2026-03-15T14:30:00.000000+00:00'),
            auditEntryId: 'entry-42',
        );

        $array = $result->toArray();

        self::assertSame('sha256-hash-value', $array['confirmation_hash']);
        self::assertSame('entry-42', $array['audit_entry_id']);
        self::assertArrayHasKey('forgotten_at', $array);
    }

    public function testConfirmationHashIsOneWay(): void
    {
        $result = new ForgetResult(
            confirmationHash: hash('sha256', 'pseudonym-abc'),
            forgottenAt: new DateTimeImmutable(),
            auditEntryId: 'aud-1',
        );

        // Hash is 64 hex chars for SHA-256
        self::assertSame(64, strlen($result->confirmationHash));
    }
}
