<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ActionResult;

final class ActionResultTest extends TestCase
{
    #[Test]
    public function success_factory(): void
    {
        $result = ActionResult::success('Record created');

        self::assertTrue($result->success);
        self::assertSame('Record created', $result->message);
        self::assertSame([], $result->metadata);
    }

    #[Test]
    public function success_with_metadata(): void
    {
        $result = ActionResult::success('Created', ['id' => '42', 'table' => 'users']);

        self::assertTrue($result->success);
        self::assertSame('Created', $result->message);
        self::assertSame(['id' => '42', 'table' => 'users'], $result->metadata);
    }

    #[Test]
    public function failure_factory(): void
    {
        $result = ActionResult::failure('Validation failed');

        self::assertFalse($result->success);
        self::assertSame('Validation failed', $result->message);
        self::assertSame([], $result->metadata);
    }

    #[Test]
    public function failure_with_metadata(): void
    {
        $result = ActionResult::failure('Not found', ['resource' => 'users', 'id' => '99']);

        self::assertFalse($result->success);
        self::assertSame('Not found', $result->message);
        self::assertSame(['resource' => 'users', 'id' => '99'], $result->metadata);
    }

    #[Test]
    public function direct_construction(): void
    {
        $result = new ActionResult(success: true, message: 'ok', metadata: ['key' => 'val']);

        self::assertTrue($result->success);
        self::assertSame('ok', $result->message);
        self::assertSame(['key' => 'val'], $result->metadata);
    }
}
