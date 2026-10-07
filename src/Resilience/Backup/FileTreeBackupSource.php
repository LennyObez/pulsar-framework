<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use FilesystemIterator;
use Generator;
use Override;
use Pulsar\Api\Api;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function basename;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function is_dir;
use function is_file;
use function is_readable;
use function realpath;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Every readable file under one directory — or one named file — streamed.
 *
 * THIS IS THE SOURCE THAT CARRIES THE AUDIT CHAIN, and that is the reason it
 * exists at all rather than being left to the operator. `AuditFileSink` writes
 * the tamper-evident trail as JSON Lines on disk; a backup primitive that copied
 * the database and not that file would produce an archive from which a regulated
 * deployment could recover its data and not its evidence — which is a worse
 * artefact than no backup, because it looks complete. {@see \Pulsar\Core\Wiring\BackupWiring}
 * therefore composes one of these over the audit directory by default and does
 * not make it optional.
 *
 * The same class covers the deployment's other file trees — uploads, generated
 * documents — because they differ only in where they are rooted.
 *
 * WHAT IT SKIPS, and it says so rather than filtering silently: directories it
 * cannot enter and files it cannot read are counted and reported through
 * {@see skipped()}, which the console command prints and a caller can assert on.
 * A backup that quietly omitted the files it could not open would be the failure
 * this whole subsystem exists to make impossible.
 *
 * SYMLINKS ARE NOT FOLLOWED. A link pointing outside the root would put arbitrary
 * files of the host into an archive whose name says it holds uploads, and a link
 * pointing back inside it produces an archive that never terminates.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class FileTreeBackupSource implements BackupSourceInterface
{
    /** Bytes read per file read. Matches the archive chunk so nothing is re-buffered. */
    private const int READ_SIZE = ArchiveFormat::CHUNK_SIZE;

    /** @var list<string> */
    private array $skipped = [];

    /**
     * @param non-empty-string $id          Becomes the first path segment of every entry
     * @param string           $root        Directory to walk; a missing one yields nothing and is reported
     * @param non-empty-string $description What this tree holds, for the coverage table
     */
    public function __construct(
        private readonly string $id,
        private readonly string $root,
        private readonly string $description,
    ) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function describe(): string
    {
        return sprintf('%s (%s)', $this->description, $this->root);
    }

    /**
     * Files this run could not read, by path.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return iterable<BackupEntry>
     *
     * @throws BackupException
     */
    #[Override]
    public function entries(): iterable
    {
        $this->skipped = [];

        // A ROOT THAT IS A SINGLE FILE contributes that one file, under its own
        // basename. This is not a convenience: the audit trail is one file inside
        // a log directory that also holds the application log, and a source that
        // could only take whole directories would force the choice between
        // omitting the evidence and sweeping every other log beside it into an
        // archive an operator may ship offsite. The restore side needs no special
        // case — the target is rooted at the file's directory, and the entry
        // lands back under the same basename.
        if (is_file($this->root)) {
            $name = basename($this->root);

            if ($name === '' || !is_readable($this->root) || !BackupEntry::isSafeName($name)) {
                throw BackupException::sourceUnreadable($this->id, sprintf('"%s" cannot be read', $this->root));
            }

            yield new BackupEntry($name, self::read($this->root, $this->id));

            return;
        }

        if (!is_dir($this->root)) {
            // Not an exception. A deployment that has never received an upload has
            // no upload directory, and refusing to back anything up because one
            // configured tree is absent would make the whole archive hostage to it.
            // The manifest records the source with no entries, which is the fact.
            $this->skipped[] = sprintf('%s (the directory does not exist)', $this->root);

            return;
        }

        $base = realpath($this->root);

        if ($base === false || !is_readable($base)) {
            throw BackupException::sourceUnreadable($this->id, sprintf('"%s" cannot be opened', $this->root));
        }

        $prefix = strlen($base) + 1;

        try {
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $base,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
        } catch (Throwable $failure) {
            throw BackupException::sourceUnreadable($this->id, $failure->getMessage(), $failure);
        }

        foreach ($walk as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, $prefix));

            if ($relative === '' || !BackupEntry::isSafeName($relative)) {
                $this->skipped[] = sprintf('%s (its path is not a safe archive entry name)', $absolute);

                continue;
            }

            if (!$file->isReadable()) {
                $this->skipped[] = sprintf('%s (unreadable)', $absolute);

                continue;
            }

            yield new BackupEntry($relative, self::read($absolute, $this->id));
        }
    }

    /**
     * @return Generator<int, string>
     *
     * @throws BackupException
     */
    private static function read(string $path, string $sourceId): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw BackupException::sourceUnreadable($sourceId, sprintf('"%s" could not be opened', $path));
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::READ_SIZE);

                if ($chunk === false) {
                    throw BackupException::sourceUnreadable(
                        $sourceId,
                        sprintf('"%s" failed mid-read, so the archive would hold a truncated copy', $path),
                    );
                }

                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
