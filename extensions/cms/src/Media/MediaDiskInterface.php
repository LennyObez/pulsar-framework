<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

/**
 * Storage disk abstraction for media file operations.
 */
#[Api(since: '1.0.0')]
interface MediaDiskInterface
{
    public function write(string $path, string $contents): void;

    public function read(string $path): string;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    public function url(string $path): string;
}
