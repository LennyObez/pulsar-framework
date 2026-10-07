<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\KeyType;
use Pulsar\Security\KeyLifecycle\RotationResult;

#[CoversClass(RotationResult::class)]
final class RotationResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = RotationResult::success(
            kid: 'key-new',
            keyType: KeyType::Encryption,
            previousKid: 'key-old',
            reason: 'scheduled',
        );

        self::assertTrue($result->success);
        self::assertSame('key-new', $result->kid);
        self::assertSame(KeyType::Encryption, $result->keyType);
        self::assertSame('key-old', $result->previousKid);
        self::assertSame('scheduled', $result->reason);
        self::assertSame([], $result->metadata);
    }

    public function testSuccessFactoryDefaults(): void
    {
        $result = RotationResult::success(kid: 'k1', keyType: KeyType::Master);

        self::assertTrue($result->success);
        self::assertNull($result->previousKid);
        self::assertSame('scheduled', $result->reason);
    }

    public function testFailureFactory(): void
    {
        $result = RotationResult::failure(
            kid: 'key-stuck',
            keyType: KeyType::Tls,
            reason: 'HSM unreachable',
            metadata: ['hsm_host' => '10.0.0.5'],
        );

        self::assertFalse($result->success);
        self::assertSame('key-stuck', $result->kid);
        self::assertSame(KeyType::Tls, $result->keyType);
        self::assertNull($result->previousKid);
        self::assertSame('HSM unreachable', $result->reason);
        self::assertSame(['hsm_host' => '10.0.0.5'], $result->metadata);
    }

    public function testToArrayContainsAllFields(): void
    {
        $result = RotationResult::success(
            kid: 'key-1',
            keyType: KeyType::Signing,
            previousKid: 'key-0',
            reason: 'compliance',
        );

        $array = $result->toArray();

        self::assertTrue($array['success']);
        self::assertSame('key-1', $array['kid']);
        self::assertSame('signing', $array['key_type']);
        self::assertSame('key-0', $array['previous_kid']);
        self::assertSame('compliance', $array['reason']);
        self::assertArrayHasKey('rotated_at', $array);
        self::assertSame([], $array['metadata']);
    }

    public function testToArrayWithFailure(): void
    {
        $result = RotationResult::failure(
            kid: 'bad-key',
            keyType: KeyType::FipsModule,
            reason: 'validation failed',
        );

        $array = $result->toArray();

        self::assertFalse($array['success']);
        self::assertSame('fips_module', $array['key_type']);
        self::assertNull($array['previous_kid']);
    }
}
