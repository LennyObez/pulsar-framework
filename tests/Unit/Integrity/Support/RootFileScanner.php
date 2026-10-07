<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function escapeshellarg;
use function exec;
use function sort;
use function str_contains;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * The rule behind RootCleanlinessTest, pointed at a root rather than at this one.
 *
 * It used to be a private method on the test, which is the same shape as a gate nobody
 * has watched refuse: the only root it could ever read was a healthy one, so the only
 * answer anyone ever saw was the empty list. Lifting it out costs the ratchet nothing —
 * it passes the repository root and asserts exactly what it asserted before — and buys
 * the one thing that was missing, which is the ability to hand it a repository with a
 * stray file in it and watch what it says.
 */
final readonly class RootFileScanner
{
    /** Where a shell sends output it should not keep. */
    private const string NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    public function __construct(private string $root) {}

    /**
     * Every file git tracks directly at the root of this checkout.
     *
     * git is the authority on what is *committed*, which is the thing being guarded.
     * Reading the directory would also see generated artefacts and local scratch files,
     * and failing on those would make the guard fire during ordinary work.
     *
     * Stderr goes to the null device, not into the list. Merged with `2>&1` it arrived
     * as data — `fatal: not a git repository` counted as a root file, and the guard
     * reported it as one, in every checkout without a repository.
     *
     * @return list<string>|null null when git cannot read this checkout at all, which
     *                           is not the same as a repository with nothing in it
     */
    public function trackedRootFiles(): ?array
    {
        $output = [];

        exec(
            'git -C ' . escapeshellarg($this->root) . ' ls-files --full-name 2>' . self::NULL_DEVICE,
            $output,
            $status,
        );

        if ($status !== 0) {
            return null;
        }

        $files = array_values(array_filter(
            $output,
            static fn(string $line): bool => $line !== '' && !str_contains($line, '/'),
        ));

        $files = array_map(trim(...), $files);
        sort($files);

        return $files;
    }

    /**
     * Tracked at the root and named by nobody: the anomaly the ratchet exists for.
     *
     * @param list<string> $tracked
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function unexpected(array $tracked, array $allowed): array
    {
        return array_values(array_diff($tracked, $allowed));
    }

    /**
     * Allowed and no longer there: permission nobody is using, and evidence the list
     * was written once and never revisited.
     *
     * @param list<string> $tracked
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function stale(array $tracked, array $allowed): array
    {
        return array_values(array_diff($allowed, $tracked));
    }
}
