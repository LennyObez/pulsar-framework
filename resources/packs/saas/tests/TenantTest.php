<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\Tenant;
use {{namespace}}\Entity\TenantStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tenant::class)]
final class TenantTest extends TestCase
{
    #[Test]
    public function it_creates_an_active_tenant(): void
    {
        $tenant = new Tenant(
            id: 'ten_001',
            name: 'Acme Corp',
            slug: 'acme-corp',
            planId: 'plan_professional',
        );

        self::assertSame('ten_001', $tenant->id);
        self::assertSame('acme-corp', $tenant->slug);
        self::assertTrue($tenant->isActive());
    }

    #[Test]
    public function it_tracks_trial_status(): void
    {
        $tenant = new Tenant(
            id: 'ten_002',
            name: 'Trial Corp',
            slug: 'trial-corp',
            planId: 'plan_starter',
            trialEndsAt: new DateTimeImmutable('+14 days'),
        );

        self::assertTrue($tenant->isOnTrial());
        self::assertFalse($tenant->isTrialExpired());
    }

    #[Test]
    public function it_detects_expired_trial(): void
    {
        $tenant = new Tenant(
            id: 'ten_003',
            name: 'Expired Corp',
            slug: 'expired-corp',
            planId: 'plan_starter',
            trialEndsAt: new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($tenant->isOnTrial());
        self::assertTrue($tenant->isTrialExpired());
    }

    // TODO: Add tests for tenant provisioning, suspension, and data isolation
}
