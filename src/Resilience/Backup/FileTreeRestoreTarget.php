<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Filesystem\SafePath;

use function chmod;
use function dirname;
use function fclose;
use function fopen;
use function fwrite;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Writes a file tree back under a root the operator chose.
 *
 * TWO CONTAINMENT CHECKS, NOT ONE, and the redundancy is deliberate.
 * {@see BackupEntry::isSafeName()} already refuses a name that could climb, and
 * the archive reader re-applies it to every name it reads; this class then
 * resolves the destination and refuses it if it does not land under the root.
 * The first check is about the SHAPE of the name and the second about WHERE it
 * ends up, and only the second catches a root that is itself a symlink into
 * somewhere else. Writing files out of an archive is the single most dangerous
 * thing this module does, and it is the one place where a redundant check is
 * cheaper than the argument for removing it.
 *
 * IT REFUSES TO OVERWRITE unless the operator said so, for the reason
 * {@see DatabaseRestoreTarget} refuses a populated table: an operator restoring
 * one lost file into a live tree must not silently roll back every other file
 * beside it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class FileTreeRestoreTarget implements RestoreTargetInterface
{
    private const int DIRECTORY_PERMISSIONS = 0o750;

    private const int FILE_PERMISSIONS = 0o640;

    /**
     * @param non-empty-string $id   The source id whose entries this target claims
     * @param string           $root Directory to write under; created if absent
     */
    public function __construct(
        private string $id,
        private string $root,
        private bool $overwriteExisting = false,
    ) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    /**
     * The same target, with overwriting permitted or refused.
     *
     * Exists so `backup:restore` can raise the permission for ONE invocation
     * without the container ever holding a target that overwrites. A destructive
     * capability that lives in a binding is one resolve away from a caller that
     * did not ask for it; a destructive capability that has to be constructed at
     * the point of use is visible in the call that constructs it.
     */
    #[NoDiscard]
    public function withOverwrite(bool $overwrite): self
    {
        return new self($this->id, $this->root, $overwrite);
    }

    #[Override]
    public function accepts(string $entryName): bool
    {
        return str_starts_with($entryName, $this->id . '/');
    }

    /**
     * @param iterable<string> $chunks
     *
     * @return int<0, max>
     *
     * @throws BackupException
     */
    #[Override]
    public function restore(string $entryName, iterable $chunks): int
    {
        $relative = substr($entryName, strlen($this->id) + 1);

        if ($relative === '' || !BackupEntry::isSafeName($relative)) {
            throw BackupException::unsafeEntryName($entryName);
        }

        $destination = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!SafePath::isWithin($destination, $this->root)) {
            throw BackupException::unsafeEntryName($entryName);
        }

        if (is_file($destination) && !$this->overwriteExisting) {
            throw BackupException::restoreFailed(
                $entryName,
                sprintf(
                    '"%s" already exists. Restoring over it would replace a file the deployment is '
                        . 'still using; re-run with --overwrite, or restore into an empty directory',
                    $destination,
                ),
            );
        }

        $directory = dirname($destination);

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_PERMISSIONS, true) && !is_dir($directory)) {
            throw BackupException::restoreFailed(
                $entryName,
                sprintf('the directory "%s" could not be created', $directory),
            );
        }

        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            throw BackupException::restoreFailed($entryName, sprintf('"%s" could not be opened', $destination));
        }

        $written = 0;

        try {
            foreach ($chunks as $chunk) {
                $length = strlen($chunk);
                $put = fwrite($handle, $chunk);

                if ($put === false || $put !== $length) {
                    throw BackupException::restoreFailed(
                        $entryName,
                        sprintf('"%s" accepted fewer bytes than the archive holds', $destination),
                    );
                }

                $written += $length;
            }
        } finally {
            fclose($handle);
        }

        @chmod($destination, self::FILE_PERMISSIONS);

        return $written;
    }
}
