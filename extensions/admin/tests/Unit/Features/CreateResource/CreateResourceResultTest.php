<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\CreateResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceResult;

#[CoversClass(CreateResourceResult::class)]
final class CreateResourceResultTest extends TestCase
{
    #[Test]
    public function constructor_wraps_action_result(): void
    {
        $actionResult = ActionResult::success('Created', ['id' => '42']);

        $result = new CreateResourceResult(result: $actionResult);

        self::assertSame($actionResult, $result->result);
        self::assertTrue($result->result->success);
        self::assertSame('42', $result->result->metadata['id']);
    }

    #[Test]
    public function wraps_failure_result(): void
    {
        $actionResult = ActionResult::failure('Validation failed');

        $result = new CreateResourceResult(result: $actionResult);

        self::assertFalse($result->result->success);
    }
}
