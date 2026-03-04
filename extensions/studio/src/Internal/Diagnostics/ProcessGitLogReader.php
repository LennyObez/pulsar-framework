<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Contracts\ProcessRunnerInterface;

use function array_slice;
use function count;
use function explode;
use function preg_match;
use function trim;

/**
 * Reads git history by delegating to a ProcessRunnerInterface implementation.
 *
 * The injected runner handles safe process execution via proc_open
 * with explicit argument arrays, timeout enforcement, and output capping.
 * This class does not execute processes directly.
 *
 * Only instantiated in the composition root with a trusted project root path.
 */
#[Internal]
final readonly class ProcessGitLogReader implements GitLogReader
{
    public function __construct(
        private ProcessRunnerInterface $runner,
    ) {}

    #[Override]
    public function getVersionTags(int $limit = 20): array
    {
        $result = $this->runner->run(['git', 'tag', '--sort=-creatordate']);

        if ($result->isError || $result->output === '') {
            return [];
        }

        $output = trim($result->output);

        if ($output === '') {
            return [];
        }

        $tags = [];

        foreach (explode("\n", $output) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }

            if (preg_match('/^v?\d+\.\d+/', $tag) === 1) {
                $tags[] = $tag;
            }
        }

        return array_slice($tags, 0, $limit);
    }

    #[Override]
    public function getCurrentRef(): string
    {
        $result = $this->runner->run(['git', 'describe', '--tags', '--always']);

        if ($result->isError || $result->output === '') {
            return 'unknown';
        }

        return trim($result->output);
    }

    #[Override]
    public function getCommitsBetween(?string $from, string $to, int $limit = 50): array
    {
        $range = $from !== null ? $from . '..' . $to : $to;

        $result = $this->runner->run([
            'git', 'log', $range,
            '--format=%H|%h|%s|%an|%ai',
            '--no-merges',
            '-' . $limit,
        ]);

        if ($result->isError || $result->output === '') {
            return [];
        }

        $commits = [];

        foreach (explode("\n", trim($result->output)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line, 5);

            if (count($parts) < 5) {
                continue;
            }

            $commits[] = [
                'hash' => $parts[0],
                'short_hash' => $parts[1],
                'subject' => $parts[2],
                'author' => $parts[3],
                'date' => $parts[4],
            ];
        }

        return $commits;
    }

    #[Override]
    public function getDiffStats(string $from, string $to): array
    {
        $result = $this->runner->run(['git', 'diff', '--shortstat', $from . '..' . $to]);

        $stats = ['files_changed' => 0, 'insertions' => 0, 'deletions' => 0];

        if ($result->isError || $result->output === '') {
            return $stats;
        }

        $output = $result->output;

        if (preg_match('/(\d+) files? changed/', $output, $m) === 1) {
            $stats['files_changed'] = (int) $m[1];
        }

        if (preg_match('/(\d+) insertions?\(\+\)/', $output, $m) === 1) {
            $stats['insertions'] = (int) $m[1];
        }

        if (preg_match('/(\d+) deletions?\(-\)/', $output, $m) === 1) {
            $stats['deletions'] = (int) $m[1];
        }

        return $stats;
    }
}
