<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\BulkAction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionResult;

#[CoversClass(BulkActionResult::class)]
final class BulkActionResultTest extends TestCase
{
    #[Test]
    public function constructor_wraps_action_result(): void
    {
        $actionResult = ActionResult::success('3 records archived');

        $result = new BulkActionResult(result: $actionResult);

        self::assertSame($actionResult, $result->result);
        self::assertTrue($result->result->success);
        self::assertSame('3 records archived', $result->result->message);
    }

    #[Test]
    public function wraps_failure_result(): void
    {
        $actionResult = ActionResult::failure('Bulk action failed');

        $result = new BulkActionResult(result: $actionResult);

        self::assertFalse($result->result->success);
    }
}
