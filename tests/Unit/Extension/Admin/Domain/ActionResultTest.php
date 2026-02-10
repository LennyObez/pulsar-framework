<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ActionResult;

#[CoversClass(ActionResult::class)]
final class ActionResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesSuccessfulResult(): void
    {
        $result = ActionResult::success('User created');

        self::assertTrue($result->success);
        self::assertSame('User created', $result->message);
        self::assertSame([], $result->metadata);
    }

    #[Test]
    public function successFactoryWithMetadata(): void
    {
        $result = ActionResult::success('User created', ['id' => 42]);

        self::assertTrue($result->success);
        self::assertSame('User created', $result->message);
        self::assertSame(['id' => 42], $result->metadata);
    }

    #[Test]
    public function failureFactoryCreatesFailedResult(): void
    {
        $result = ActionResult::failure('Validation failed');

        self::assertFalse($result->success);
        self::assertSame('Validation failed', $result->message);
        self::assertSame([], $result->metadata);
    }

    #[Test]
    public function failureFactoryWithMetadata(): void
    {
        $result = ActionResult::failure('Validation failed', ['errors' => ['name' => 'required']]);

        self::assertFalse($result->success);
        self::assertSame(['errors' => ['name' => 'required']], $result->metadata);
    }

    #[Test]
    public function constructDirectly(): void
    {
        $result = new ActionResult(
            success: true,
            message: 'Done',
            metadata: ['key' => 'value'],
        );

        self::assertTrue($result->success);
        self::assertSame('Done', $result->message);
        self::assertSame(['key' => 'value'], $result->metadata);
    }

    #[Test]
    public function defaultMetadataIsEmpty(): void
    {
        $result = new ActionResult(success: false, message: 'Error');

        self::assertSame([], $result->metadata);
    }
}
