<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\UpdateResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceResult;

#[CoversClass(UpdateResourceResult::class)]
final class UpdateResourceResultTest extends TestCase
{
    #[Test]
    public function constructorWrapsActionResult(): void
    {
        $actionResult = ActionResult::success('Updated');

        $result = new UpdateResourceResult(result: $actionResult);

        self::assertSame($actionResult, $result->result);
        self::assertTrue($result->result->success);
    }
}
