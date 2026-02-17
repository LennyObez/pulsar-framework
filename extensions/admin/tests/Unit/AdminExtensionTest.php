<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\AdminExtension;
use Pulsar\Extension\Admin\AdminServiceProvider;

final class AdminExtensionTest extends TestCase
{
    private AdminExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new AdminExtension();
    }

    #[Test]
    public function name_returns_correct_identifier(): void
    {
        self::assertSame('pulsar/admin', $this->extension->name());
    }

    #[Test]
    public function providers_returns_admin_service_provider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(AdminServiceProvider::class, $providers[0]);
    }
}
