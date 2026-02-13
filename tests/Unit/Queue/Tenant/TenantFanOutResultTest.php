<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Tenant\FanOutResult;

#[CoversClass(FanOutResult::class)]
final class FanOutResultTest extends TestCase
{
    #[Test]
    public function constructionAndAccessors(): void
    {
        $result = new FanOutResult(
            dispatched: 5,
            failed: 2,
            failedTenantIds: ['bad-tenant-1', 'bad-tenant-2'],
        );

        self::assertSame(5, $result->dispatched);
        self::assertSame(2, $result->failed);
        self::assertSame(['bad-tenant-1', 'bad-tenant-2'], $result->failedTenantIds);
    }
}
