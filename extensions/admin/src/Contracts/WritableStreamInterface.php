<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;

/**
 * Writable byte stream for export output.
 */
#[Api(since: '1.0.0')]
interface WritableStreamInterface
{
    /**
     * Write data to the stream.
     */
    public function write(string $data): void;

    /**
     * Get the accumulated contents.
     */
    public function contents(): string;

    /**
     * Close the stream and finalize.
     */
    public function close(): void;
}
