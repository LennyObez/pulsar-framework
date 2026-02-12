<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Safely extracts theme archives with Zip Slip protection.
 */
#[Api(since: '1.0.0')]
interface ThemeArchiveExtractorInterface
{
    /**
     * Extract a theme archive to the target directory.
     *
     * Validates every entry for path traversal attacks, symlinks, null bytes,
     * and enforces maximum archive size and file count limits.
     *
     * @throws CmsException If extraction fails due to security violations
     */
    public function extract(string $archivePath, string $targetDirectory): ExtractResult;
}
