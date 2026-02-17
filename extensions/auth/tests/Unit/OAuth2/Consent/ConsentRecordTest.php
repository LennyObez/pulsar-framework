<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Consent;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Consent\ConsentRecord;

#[CoversClass(ConsentRecord::class)]
final class ConsentRecordTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $grantedAt = new DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $record = new ConsentRecord(
            id: 'consent-001',
            subjectId: 'user-42',
            clientId: 'client-1',
            scopes: ['openid', 'profile', 'email'],
            grantedAt: $grantedAt,
        );

        self::assertSame('consent-001', $record->id);
        self::assertSame('user-42', $record->subjectId);
        self::assertSame('client-1', $record->clientId);
        self::assertSame(['openid', 'profile', 'email'], $record->scopes);
        self::assertSame($grantedAt, $record->grantedAt);
    }

    #[Test]
    public function emptyScopes(): void
    {
        $record = new ConsentRecord(
            id: 'consent-002',
            subjectId: 'user-42',
            clientId: 'client-1',
            scopes: [],
            grantedAt: new DateTimeImmutable(),
        );

        self::assertSame([], $record->scopes);
    }
}
