<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\HotReload;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\HotReload\FileChangeType;

#[CoversNothing]
final class FileChangeTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('created', FileChangeType::Created->value);
        self::assertSame('modified', FileChangeType::Modified->value);
        self::assertSame('deleted', FileChangeType::Deleted->value);
    }

    #[Test]
    public function enumHasExactlyThreeCases(): void
    {
        self::assertCount(3, FileChangeType::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(FileChangeType::Created, FileChangeType::from('created'));
        self::assertSame(FileChangeType::Modified, FileChangeType::from('modified'));
        self::assertSame(FileChangeType::Deleted, FileChangeType::from('deleted'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(FileChangeType::tryFrom('renamed'));
    }
}
