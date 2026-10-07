<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation\Support;

use PHPUnit\Framework\Assert;

use function array_filter;
use function array_values;
use function explode;
use function fclose;
use function fwrite;
use function implode;
use function is_file;
use function proc_close;
use function proc_open;
use function sort;
use function str_ends_with;
use function stream_get_contents;

/**
 * What the repository ships, asked of git: tracked files plus new ones .gitignore does not
 * exclude. A walk of the disk would also read gitignored local files (docs/audit/, scratch
 * plans) that no clone has, so a gate would pass here and fail in CI, or the reverse.
 */
final class TrackedFiles
{
    /**
     * Repository-relative paths ending in $suffix under the given pathspecs, sorted.
     *
     * @return list<string>
     */
    public static function under(string $root, string $suffix, string ...$pathspecs): array
    {
        $files = [];

        foreach (explode("\0", self::git($root, null, 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', ...$pathspecs)) as $path) {
            // --cached lists the index, which still names a file deleted but not yet staged.
            if ($path !== '' && str_ends_with($path, $suffix) && is_file($root . '/' . $path)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The given repository-relative paths that .gitignore excludes.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public static function ignored(string $root, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $output = self::git($root, implode("\0", $paths) . "\0", 'check-ignore', '--stdin', '-z');

        return array_values(array_filter(explode("\0", $output), static fn(string $p): bool => $p !== ''));
    }

    private static function git(string $root, ?string $stdin, string ...$arguments): string
    {
        $process = proc_open(array_values(['git', ...$arguments]), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        Assert::assertIsResource($process, 'git could not be started');

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        // check-ignore answers 1 when nothing is ignored; anything else non-zero is a failure.
        Assert::assertTrue($code === 0 || ($code === 1 && $arguments[0] === 'check-ignore'), 'git ' . $arguments[0] . ' failed: ' . $error);

        return $output;
    }
}
