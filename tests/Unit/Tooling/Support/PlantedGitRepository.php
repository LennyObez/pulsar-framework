<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_values;
use function bin2hex;
use function chmod;
use function dirname;
use function fclose;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function rtrim;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A throwaway git working tree with a planted history, for the PR gates to judge.
 *
 * check-adr.sh, check-pr-size.sh and check-pr-title-scope.sh all decide by asking
 * git what a branch changed against its base. Testing them therefore needs a real
 * repository with a real base — the alternative, stubbing git, would test the stub.
 *
 * The base is published as `refs/remotes/origin/<branch>` because that is the ref
 * the gates name (`origin/${BASE_REF}...HEAD`); a plain local branch would leave
 * the diff range empty, and an empty range is precisely how these gates pass
 * without measuring anything.
 *
 * Everything lives under the system temp directory and is removed in tearDown.
 * Nothing here touches the repository the tests are running from.
 */
final class PlantedGitRepository
{
    private function __construct(public readonly string $path) {}

    /**
     * Create an initialised repository with one empty base commit.
     */
    public static function create(string $label): self
    {
        $path = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'pulsar-gate-' . $label . '-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0o700, true) && !is_dir($path)) {
            throw new RuntimeException('could not create the planted repository at ' . $path);
        }

        $repository = new self($path);
        $repository->git('init', '--quiet', '--initial-branch=main', '.');
        $repository->git('commit', '--quiet', '--allow-empty', '-m', 'base');

        return $repository;
    }

    /**
     * Write a file into the working tree, creating parent directories.
     */
    public function write(string $relativePath, string $contents): void
    {
        $target = $this->path . DIRECTORY_SEPARATOR . $relativePath;
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('could not create ' . $directory);
        }

        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('could not write ' . $target);
        }
    }

    /**
     * Stage everything and commit it.
     */
    public function commit(string $message): void
    {
        $this->git('add', '--all', '.');
        $this->git('commit', '--quiet', '-m', $message);
    }

    /**
     * Publish HEAD as the base the gates will diff against.
     */
    public function publishAsOrigin(string $branch = 'main'): void
    {
        $this->git('update-ref', 'refs/remotes/origin/' . $branch, 'HEAD');
    }

    /**
     * The current commit hash — check-adr.sh reads its base from BASE_SHA.
     */
    public function headSha(): string
    {
        return trim($this->git('rev-parse', 'HEAD'));
    }


    /**
     * Delete the tree. A verification fixture left on disk is an incident of its own.
     */
    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());

                continue;
            }

            // Git marks objects and packs read-only; unlink refuses those on Windows.
            chmod($entry->getPathname(), 0o600);
            unlink($entry->getPathname());
        }

        rmdir($this->path);
    }

    /**
     * Run one git command in the tree and return its stdout.
     *
     * Identity and signing are pinned on the command line so the result does not
     * depend on the developer's global git configuration: a machine configured to
     * sign every commit would otherwise fail these tests for want of a key.
     */
    private function git(string ...$arguments): string
    {
        // array_values, because proc_open wants a list and a spread of a variadic
        // does not guarantee one.
        $command = array_values([
            'git',
            '-c', 'user.email=gate-test@pulsar.invalid',
            '-c', 'user.name=Gate Test',
            '-c', 'commit.gpgsign=false',
            '-c', 'tag.gpgsign=false',
            '-c', 'core.hooksPath=/dev/null',
            '-C', $this->path,
            ...$arguments,
        ]);

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->path,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('could not run git ' . implode(' ', $arguments));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);

        if ($status !== 0) {
            throw new RuntimeException(
                'git ' . implode(' ', $arguments) . ' failed (' . $status . '): ' . $stderr . $stdout,
            );
        }

        return $stdout;
    }
}
