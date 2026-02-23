<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\ConflictReporter;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\GeneratedFileSet;

#[CoversClass(ConflictReporter::class)]
final class ConflictReporterTest extends TestCase
{
    #[Test]
    public function reportReturnsEmptyWhenNoConflicts(): void
    {
        $reporter = new ConflictReporter();
        $fileSet = new GeneratedFileSet([
            new GeneratedFile('/nonexistent/a.php', 'content'),
            new GeneratedFile('/nonexistent/b.php', 'content'),
        ]);

        self::assertSame([], $reporter->report($fileSet));
    }

    #[Test]
    public function reportReturnsSortedConflictPaths(): void
    {
        $reporter = new ConflictReporter();

        // Use this test file as a known-existing file
        $existingPath = __FILE__;

        $fileSet = new GeneratedFileSet([
            new GeneratedFile($existingPath, 'content'),
            new GeneratedFile('/nonexistent/path.php', 'content'),
        ]);

        $conflicts = $reporter->report($fileSet);

        self::assertSame([$existingPath], $conflicts);
    }

    #[Test]
    public function hasConflictsReturnsFalseWhenNoneExist(): void
    {
        $reporter = new ConflictReporter();
        $fileSet = new GeneratedFileSet([
            new GeneratedFile('/nonexistent/file.php', 'content'),
        ]);

        self::assertFalse($reporter->hasConflicts($fileSet));
    }

    #[Test]
    public function hasConflictsReturnsTrueWhenConflictsExist(): void
    {
        $reporter = new ConflictReporter();
        $fileSet = new GeneratedFileSet([
            new GeneratedFile(__FILE__, 'content'),
        ]);

        self::assertTrue($reporter->hasConflicts($fileSet));
    }

    #[Test]
    public function reportIsDeterministic(): void
    {
        $reporter = new ConflictReporter();

        // Use two known-existing files
        $file1 = __FILE__;
        $file2 = __DIR__ . '/GeneratedFileTest.php';

        $fileSet = new GeneratedFileSet([
            new GeneratedFile($file2, 'content'),
            new GeneratedFile($file1, 'content'),
        ]);

        $result1 = $reporter->report($fileSet);
        $result2 = $reporter->report($fileSet);

        self::assertSame($result1, $result2);
    }
}
