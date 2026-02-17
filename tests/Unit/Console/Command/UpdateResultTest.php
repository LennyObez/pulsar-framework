<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\UpdateResult;

#[CoversClass(UpdateResult::class)]
final class UpdateResultTest extends TestCase
{
    #[Test]
    public function okCreatesSuccessfulResult(): void
    {
        $result = UpdateResult::ok();

        self::assertTrue($result->success);
        self::assertSame('', $result->errorMessage);
        self::assertSame('', $result->notes);
    }

    #[Test]
    public function okAcceptsPostUpdateNotes(): void
    {
        $result = UpdateResult::ok('Run migrations after updating.');

        self::assertTrue($result->success);
        self::assertSame('Run migrations after updating.', $result->notes);
    }

    #[Test]
    public function failedCreatesFailedResult(): void
    {
        $result = UpdateResult::failed('Composer conflict detected');

        self::assertFalse($result->success);
        self::assertSame('Composer conflict detected', $result->errorMessage);
        self::assertSame('', $result->notes);
    }

    #[Test]
    public function constructorAssignsAllProperties(): void
    {
        $result = new UpdateResult(
            success: true,
            errorMessage: 'warning',
            notes: 'post-update note',
        );

        self::assertTrue($result->success);
        self::assertSame('warning', $result->errorMessage);
        self::assertSame('post-update note', $result->notes);
    }

    #[Test]
    public function defaultsToEmptyStrings(): void
    {
        $result = new UpdateResult(success: false);

        self::assertFalse($result->success);
        self::assertSame('', $result->errorMessage);
        self::assertSame('', $result->notes);
    }
}
