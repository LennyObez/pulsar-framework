<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\CompromiseResponseResult;
use Pulsar\Security\KeyLifecycle\KeyType;

#[CoversClass(CompromiseResponseResult::class)]
final class CompromiseResponseResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = CompromiseResponseResult::success(
            compromisedKid: 'old-key',
            newKid: 'new-key',
            incidentId: 'inc-42',
            keyType: KeyType::Encryption,
        );

        self::assertTrue($result->success);
        self::assertSame('old-key', $result->compromisedKid);
        self::assertSame('new-key', $result->newKid);
        self::assertSame('inc-42', $result->incidentId);
        self::assertSame(KeyType::Encryption, $result->keyType);
        self::assertSame('', $result->failureReason);
    }

    public function testFailedFactory(): void
    {
        $result = CompromiseResponseResult::failed(
            compromisedKid: 'stuck-key',
            reason: 'Key store locked',
        );

        self::assertFalse($result->success);
        self::assertSame('stuck-key', $result->compromisedKid);
        self::assertNull($result->newKid);
        self::assertNull($result->incidentId);
        self::assertNull($result->keyType);
        self::assertSame('Key store locked', $result->failureReason);
    }
}
