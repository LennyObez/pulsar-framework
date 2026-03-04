<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Pulsar\Api\Internal;

/**
 * Contract for reading deployment history data.
 *
 * Implementations may use git, deployment manifests, or any other source.
 * The controller never executes processes directly.
 */
#[Internal]
interface GitLogReader
{
    /**
     * Get version tags sorted by creation date (newest first).
     *
     * @return list<string>
     */
    public function getVersionTags(int $limit = 20): array;

    /**
     * Get the current git ref (branch or tag).
     */
    public function getCurrentRef(): string;

    /**
     * Get commits between two refs.
     *
     * @return list<array{hash: string, short_hash: string, subject: string, author: string, date: string}>
     */
    public function getCommitsBetween(?string $from, string $to, int $limit = 50): array;

    /**
     * Get diff statistics between two refs.
     *
     * @return array{files_changed: int, insertions: int, deletions: int}
     */
    public function getDiffStats(string $from, string $to): array;
}
