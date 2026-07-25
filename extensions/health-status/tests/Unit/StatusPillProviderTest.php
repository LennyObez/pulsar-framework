<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\StatusPill;
use Pulsar\Extension\HealthStatus\StatusPillProvider;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use RuntimeException;

#[CoversClass(StatusPillProvider::class)]
#[CoversClass(StatusPill::class)]
final class StatusPillProviderTest extends TestCase
{
    #[Test]
    public function reportsTheWorstStatusInTheWindow(): void
    {
        $store = $this->storeReturning([
            $this->snapshot(HealthStatus::Healthy),
            $this->snapshot(HealthStatus::Degraded),
            $this->snapshot(HealthStatus::Healthy),
        ]);

        $pill = new StatusPillProvider($store)->pill();

        self::assertSame(StatusPill::DEGRADED, $pill->status);
        self::assertSame('Degraded', $pill->label);
    }

    #[Test]
    public function anyUnhealthySnapshotMakesTheWorstUnhealthy(): void
    {
        $store = $this->storeReturning([
            $this->snapshot(HealthStatus::Healthy),
            $this->snapshot(HealthStatus::Unhealthy),
            $this->snapshot(HealthStatus::Degraded),
        ]);

        self::assertSame(StatusPill::UNHEALTHY, new StatusPillProvider($store)->pill()->status);
    }

    #[Test]
    public function allHealthyReportsOperational(): void
    {
        $store = $this->storeReturning([$this->snapshot(HealthStatus::Healthy)]);

        $pill = new StatusPillProvider($store)->pill();

        self::assertSame(StatusPill::HEALTHY, $pill->status);
        self::assertSame('Operational', $pill->label);
    }

    #[Test]
    public function failsOpenToUnknownWhenThereIsNoData(): void
    {
        $store = $this->storeReturning([]);

        self::assertSame(StatusPill::UNKNOWN, new StatusPillProvider($store)->pill()->status);
    }

    #[Test]
    public function failsOpenToUnknownWhenTheStoreThrows(): void
    {
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('snapshotsBetween')->willThrowException(new RuntimeException('db down'));

        // A store outage must degrade to neutral, never to a false "operational".
        self::assertSame(StatusPill::UNKNOWN, new StatusPillProvider($store)->pill()->status);
    }

    #[Test]
    public function returnsACachedPillWithoutQueryingTheStore(): void
    {
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        // If the store is consulted this returns healthy; the cache must win.
        $store->method('snapshotsBetween')->willReturn([$this->snapshot(HealthStatus::Healthy)]);

        $cache = $this->arrayCache();
        $cache->set('health_status.footer_pill', ['status' => StatusPill::UNHEALTHY, 'label' => 'Outage']);

        $pill = new StatusPillProvider($store, $cache)->pill();

        self::assertSame(StatusPill::UNHEALTHY, $pill->status);
    }

    /**
     * @param list<HealthSnapshot> $snapshots
     */
    private function storeReturning(array $snapshots): HealthHistoryStoreInterface
    {
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('snapshotsBetween')->willReturn($snapshots);

        return $store;
    }

    private function snapshot(HealthStatus $status): HealthSnapshot
    {
        return new HealthSnapshot('id-' . $status->value, $status, [], 1.0, new DateTimeImmutable('now'));
    }

    private function arrayCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }
        };
    }
}
