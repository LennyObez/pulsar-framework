<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\DeleteResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceResult;

#[CoversClass(DeleteResourceResult::class)]
final class DeleteResourceResultTest extends TestCase
{
    #[Test]
    public function constructorWrapsActionResult(): void
    {
        $actionResult = ActionResult::success('Deleted');

        $result = new DeleteResourceResult(result: $actionResult);

        self::assertSame($actionResult, $result->result);
        self::assertTrue($result->result->success);
    }
}
