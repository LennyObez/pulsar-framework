<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Auth\CmsPermission;

#[CoversNothing]
final class CmsPermissionTest extends TestCase
{
    #[Test]
    public function gdpr_erase_permission_exists(): void
    {
        $permission = CmsPermission::ToolsGdprErase;

        self::assertSame('tools.gdpr.erase', $permission->value);
    }

    #[Test]
    public function tools_export_permission_exists(): void
    {
        $permission = CmsPermission::ToolsExport;

        self::assertSame('tools.export', $permission->value);
    }

    #[Test]
    public function gdpr_erase_is_distinct_from_export(): void
    {
        self::assertNotSame(
            CmsPermission::ToolsExport->value,
            CmsPermission::ToolsGdprErase->value,
        );
    }
}
