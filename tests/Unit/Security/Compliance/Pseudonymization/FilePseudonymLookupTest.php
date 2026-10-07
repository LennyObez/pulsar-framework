<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Pseudonymization;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Pseudonymization\FilePseudonymLookup;
use Pulsar\Security\Exception\SecurityException;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(FilePseudonymLookup::class)]
final class FilePseudonymLookupTest extends TestCase
{
    private string $directory;

    private string $path;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pulsar-pseudonyms-', true);
        $this->path = $this->directory . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'pseudonyms.json';
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        foreach ([$this->directory . DIRECTORY_SEPARATOR . 'state', $this->directory] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * The property the in-memory lookup cannot have, and the reason this class
     * exists: a second instance over the same path — which is what the next
     * process is — resolves what the first one stored.
     */
    #[Test]
    public function aMappingSurvivesTheInstanceThatWroteIt(): void
    {
        new FilePseudonymLookup($this->path)->store('subject-1', 'pseudo-1', 'encrypted-salt-1');

        $reopened = new FilePseudonymLookup($this->path);
        $mapping = $reopened->findBySubjectId('subject-1');

        self::assertNotNull($mapping);
        self::assertSame('subject-1', $mapping->subjectId);
        self::assertSame('pseudo-1', $mapping->pseudonym);
        self::assertSame('encrypted-salt-1', $mapping->encryptedSalt);
    }

    #[Test]
    public function theReverseLookupResolvesAPseudonymToItsSubject(): void
    {
        $lookup = new FilePseudonymLookup($this->path);
        $lookup->store('subject-1', 'pseudo-1', 'salt-1');
        $lookup->store('subject-2', 'pseudo-2', 'salt-2');

        $mapping = new FilePseudonymLookup($this->path)->findByPseudonym('pseudo-2');

        self::assertNotNull($mapping);
        self::assertSame('subject-2', $mapping->subjectId);
    }

    #[Test]
    public function anUnknownSubjectAndAnUnknownPseudonymBothResolveToNothing(): void
    {
        $lookup = new FilePseudonymLookup($this->path);
        $lookup->store('subject-1', 'pseudo-1', 'salt-1');

        self::assertNull($lookup->findBySubjectId('subject-absent'));
        self::assertNull($lookup->findByPseudonym('pseudo-absent'));
    }

    #[Test]
    public function anEmptyTableResolvesToNothingRatherThanFailing(): void
    {
        $lookup = new FilePseudonymLookup($this->path);

        self::assertNull($lookup->findBySubjectId('subject-1'));
        self::assertNull($lookup->findByPseudonym('pseudo-1'));
        self::assertFalse($lookup->delete('subject-1'));
    }

    /**
     * Erasure is the control this table serves, so the erased subject id must be
     * gone from the FILE and not merely from the answer. A tombstone left behind
     * would report a deletion the bytes on disk contradict.
     */
    #[Test]
    public function deletionRemovesTheSubjectFromTheFileItself(): void
    {
        $lookup = new FilePseudonymLookup($this->path);
        $lookup->store('subject-erase-me', 'pseudo-1', 'salt-1');
        $lookup->store('subject-keep', 'pseudo-2', 'salt-2');

        self::assertTrue($lookup->delete('subject-erase-me'));
        self::assertFalse($lookup->delete('subject-erase-me'));

        $contents = (string) file_get_contents($this->path);
        self::assertStringNotContainsString('subject-erase-me', $contents);
        self::assertStringNotContainsString('pseudo-1', $contents);
        self::assertStringContainsString('subject-keep', $contents);

        self::assertNull($lookup->findBySubjectId('subject-erase-me'));
        self::assertNull($lookup->findByPseudonym('pseudo-1'));
        self::assertNotNull($lookup->findBySubjectId('subject-keep'));
    }

    #[Test]
    public function storingTheSameSubjectTwiceReplacesTheMappingRatherThanDuplicatingIt(): void
    {
        $lookup = new FilePseudonymLookup($this->path);
        $lookup->store('subject-1', 'pseudo-old', 'salt-old');
        $lookup->store('subject-1', 'pseudo-new', 'salt-new');

        $mapping = $lookup->findBySubjectId('subject-1');

        self::assertNotNull($mapping);
        self::assertSame('pseudo-new', $mapping->pseudonym);
        self::assertNull($lookup->findByPseudonym('pseudo-old'));

        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode((string) file_get_contents($this->path), true);
        self::assertCount(1, $decoded);
    }

    /**
     * A corrupt table is not an empty one.
     *
     * Reading it as empty would answer "no mapping" for every live subject —
     * reporting an erasure that never happened, and losing the ability to resolve
     * a pseudonym that is still in circulation. It fails loudly instead.
     */
    #[Test]
    public function aCorruptTableFailsRatherThanReadingAsEmpty(): void
    {
        new FilePseudonymLookup($this->path)->store('subject-1', 'pseudo-1', 'salt-1');
        file_put_contents($this->path, '{ this is not json');

        $this->expectException(SecurityException::class);

        new FilePseudonymLookup($this->path)->findBySubjectId('subject-1');
    }

    #[Test]
    public function aTableHoldingSomethingOtherThanAnObjectFails(): void
    {
        new FilePseudonymLookup($this->path)->store('subject-1', 'pseudo-1', 'salt-1');
        file_put_contents($this->path, '"a string"');

        $this->expectException(SecurityException::class);

        new FilePseudonymLookup($this->path)->findBySubjectId('subject-1');
    }

    #[Test]
    public function theDirectoryIsCreatedOnFirstWrite(): void
    {
        self::assertDirectoryDoesNotExist($this->directory . DIRECTORY_SEPARATOR . 'state');

        new FilePseudonymLookup($this->path)->store('subject-1', 'pseudo-1', 'salt-1');

        self::assertFileExists($this->path);
    }
}
