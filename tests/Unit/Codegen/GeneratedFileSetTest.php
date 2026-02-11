<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\GeneratedFileSet;

#[CoversClass(GeneratedFileSet::class)]
final class GeneratedFileSetTest extends TestCase
{
    #[Test]
    public function emptySetHasNoFilesOrConflicts(): void
    {
        $set = new GeneratedFileSet();

        self::assertSame([], $set->files());
        self::assertSame([], $set->conflicts());
        self::assertFalse($set->hasConflicts());
    }

    #[Test]
    public function filesSortedByTargetPath(): void
    {
        $fileB = new GeneratedFile('/b/file.php', 'content-b');
        $fileA = new GeneratedFile('/a/file.php', 'content-a');
        $fileC = new GeneratedFile('/c/file.php', 'content-c');

        $set = new GeneratedFileSet([$fileB, $fileA, $fileC]);

        $paths = array_map(
            static fn(GeneratedFile $f): string => $f->targetPath,
            $set->files(),
        );

        self::assertSame(['/a/file.php', '/b/file.php', '/c/file.php'], $paths);
    }

    #[Test]
    public function conflictsDetectsExistingFiles(): void
    {
        // Use a path that actually exists on disk (this test file itself)
        $existingPath = __FILE__;
        $nonExistingPath = __DIR__ . '/nonexistent_file_12345.php';

        $set = new GeneratedFileSet([
            new GeneratedFile($existingPath, 'content'),
            new GeneratedFile($nonExistingPath, 'content'),
        ]);

        self::assertTrue($set->hasConflicts());
        self::assertCount(1, $set->conflicts());
        self::assertSame($existingPath, $set->conflicts()[0]->targetPath);
    }

    #[Test]
    public function noConflictsWhenAllPathsAreNew(): void
    {
        $set = new GeneratedFileSet([
            new GeneratedFile('/nonexistent/path1.php', 'content'),
            new GeneratedFile('/nonexistent/path2.php', 'content'),
        ]);

        self::assertFalse($set->hasConflicts());
        self::assertSame([], $set->conflicts());
    }

    #[Test]
    public function sortingIsDeterministic(): void
    {
        $files = [
            new GeneratedFile('/z/file.php', 'z'),
            new GeneratedFile('/a/file.php', 'a'),
            new GeneratedFile('/m/file.php', 'm'),
        ];

        $set1 = new GeneratedFileSet($files);
        $set2 = new GeneratedFileSet(array_reverse($files));

        $paths1 = array_map(static fn(GeneratedFile $f): string => $f->targetPath, $set1->files());
        $paths2 = array_map(static fn(GeneratedFile $f): string => $f->targetPath, $set2->files());

        self::assertSame($paths1, $paths2);
    }
}
