<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Tenant;

#[CoversClass(Tenant::class)]
final class TenantTest extends TestCase
{
    #[Test]
    public function constructsWithAllFields(): void
    {
        $tenant = new Tenant(
            id: 'acme',
            name: 'Acme Corp',
            metadata: ['plan' => 'enterprise', 'region' => 'us-east'],
        );

        self::assertSame('acme', $tenant->id);
        self::assertSame('Acme Corp', $tenant->name);
        self::assertSame(['plan' => 'enterprise', 'region' => 'us-east'], $tenant->metadata);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $tenant = Tenant::fromArray('acme', [
            'name' => 'Acme Corp',
            'metadata' => ['plan' => 'enterprise'],
        ]);

        self::assertSame('acme', $tenant->id);
        self::assertSame('Acme Corp', $tenant->name);
        self::assertSame(['plan' => 'enterprise'], $tenant->metadata);
    }

    #[Test]
    public function fromArrayWithMinimalDataDefaultsNameToId(): void
    {
        $tenant = Tenant::fromArray('acme', []);

        self::assertSame('acme', $tenant->id);
        self::assertSame('acme', $tenant->name);
        self::assertSame([], $tenant->metadata);
    }

    #[Test]
    public function metadataIsAccessible(): void
    {
        $metadata = ['plan' => 'starter', 'seats' => 5, 'features' => ['sso', 'api']];
        $tenant = new Tenant(id: 'test', name: 'Test', metadata: $metadata);

        self::assertSame('starter', $tenant->metadata['plan']);
        self::assertSame(5, $tenant->metadata['seats']);
        self::assertSame(['sso', 'api'], $tenant->metadata['features']);
    }
}
