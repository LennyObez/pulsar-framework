<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\IdempotencyClaim;
use Pulsar\Idempotency\IdempotencyStoreInterface;
use Pulsar\Idempotency\TenantAwareIdempotencyStore;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;

#[CoversClass(TenantAwareIdempotencyStore::class)]
final class TenantAwareIdempotencyStoreTest extends TestCase
{
    #[Test]
    public function namespacesClaimKeyByTenantId(): void
    {
        $inner = new RecordingIdempotencyStore();
        $context = new TenantContext();
        $context->set(new Tenant(id: 'acme', name: 'Acme Corp'));

        $store = new TenantAwareIdempotencyStore($inner, $context);
        $store->claim('user-create-17', 'hash', 'createIntent', new DateTimeImmutable(), 3600);

        self::assertSame("tenant\0acme\0user-create-17", $inner->lastClaimKey);
    }

    #[Test]
    public function namespacesCommitAndReleaseByTenantId(): void
    {
        $inner = new RecordingIdempotencyStore();
        $context = new TenantContext();
        $context->set(new Tenant(id: 'acme', name: 'Acme Corp'));

        $store = new TenantAwareIdempotencyStore($inner, $context);
        $store->commit('payment-42', 'payload');
        $store->release('payment-99');

        self::assertSame("tenant\0acme\0payment-42", $inner->lastCommitKey);
        self::assertSame("tenant\0acme\0payment-99", $inner->lastReleaseKey);
    }

    #[Test]
    public function twoTenantsWithSameLogicalKeyReceiveDifferentStoreKeys(): void
    {
        $inner = new RecordingIdempotencyStore();
        $context = new TenantContext();

        $store = new TenantAwareIdempotencyStore($inner, $context);

        $context->set(new Tenant(id: 'tenant-a', name: 'A'));
        $store->claim('shared-key', 'hashA', 'op', new DateTimeImmutable(), 3600);
        $aKey = $inner->lastClaimKey;

        $context->set(new Tenant(id: 'tenant-b', name: 'B'));
        $store->claim('shared-key', 'hashB', 'op', new DateTimeImmutable(), 3600);
        $bKey = $inner->lastClaimKey;

        self::assertNotSame($aKey, $bKey);
        self::assertSame("tenant\0tenant-a\0shared-key", $aKey);
        self::assertSame("tenant\0tenant-b\0shared-key", $bKey);
    }

    #[Test]
    public function passesKeyThroughWhenNoTenantResolved(): void
    {
        // Bootstrap calls and CLI commands run without a tenant. The
        // decorator must not synthesise a fake tenant prefix in that case.
        $inner = new RecordingIdempotencyStore();
        $context = new TenantContext();

        $store = new TenantAwareIdempotencyStore($inner, $context);
        $store->claim('untenanted-key', 'hash', 'op', new DateTimeImmutable(), 3600);

        self::assertSame('untenanted-key', $inner->lastClaimKey);
    }

    #[Test]
    public function pruneIsForwardedUnchanged(): void
    {
        $inner = new RecordingIdempotencyStore();
        $context = new TenantContext();
        $context->set(new Tenant(id: 'acme', name: 'Acme Corp'));

        $store = new TenantAwareIdempotencyStore($inner, $context);
        $threshold = new DateTimeImmutable('2025-01-01T00:00:00Z');
        $store->prune($threshold);

        self::assertSame($threshold, $inner->lastPruneBefore);
    }
}

final class RecordingIdempotencyStore implements IdempotencyStoreInterface
{
    public ?string $lastClaimKey = null;
    public ?string $lastCommitKey = null;
    public ?string $lastReleaseKey = null;
    public ?DateTimeImmutable $lastPruneBefore = null;

    #[Override]
    public function claim(
        string $key,
        string $parametersHash,
        string $operation,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): IdempotencyClaim {
        $this->lastClaimKey = $key;

        return IdempotencyClaim::claimed();
    }

    #[Override]
    public function commit(string $key, string $resultPayload): void
    {
        $this->lastCommitKey = $key;
    }

    #[Override]
    public function release(string $key): void
    {
        $this->lastReleaseKey = $key;
    }

    #[Override]
    public function prune(DateTimeImmutable $before): int
    {
        $this->lastPruneBefore = $before;

        return 0;
    }
}
