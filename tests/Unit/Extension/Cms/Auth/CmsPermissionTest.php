<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Auth\CmsPermission;

#[CoversNothing]
final class CmsPermissionTest extends TestCase
{
    #[Test]
    public function contentPermissionValues(): void
    {
        self::assertSame('content.view', CmsPermission::ContentView->value);
        self::assertSame('content.create', CmsPermission::ContentCreate->value);
        self::assertSame('content.update', CmsPermission::ContentUpdate->value);
        self::assertSame('content.delete', CmsPermission::ContentDelete->value);
        self::assertSame('content.publish', CmsPermission::ContentPublish->value);
        self::assertSame('content.manage', CmsPermission::ContentManage->value);
    }

    #[Test]
    public function mediaPermissionValues(): void
    {
        self::assertSame('media.view', CmsPermission::MediaView->value);
        self::assertSame('media.upload', CmsPermission::MediaUpload->value);
        self::assertSame('media.delete', CmsPermission::MediaDelete->value);
        self::assertSame('media.manage', CmsPermission::MediaManage->value);
    }

    #[Test]
    public function otherPermissionValues(): void
    {
        self::assertSame('order.view', CmsPermission::OrderView->value);
        self::assertSame('order.manage', CmsPermission::OrderManage->value);
        self::assertSame('product.manage', CmsPermission::ProductManage->value);
        self::assertSame('settings.manage', CmsPermission::SettingsManage->value);
        self::assertSame('collaboration.join', CmsPermission::CollaborationJoin->value);
        self::assertSame('collaboration.manage', CmsPermission::CollaborationManage->value);
        self::assertSame('ai.use', CmsPermission::AiUse->value);
    }

    #[Test]
    public function allCasesCount(): void
    {
        self::assertCount(19, CmsPermission::cases());
    }
}
