<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor;

use Pulsar\Api\Api;

/**
 * Contract for a block type that can be registered with the block editor.
 *
 * Each implementation defines a unique type identifier, a JSON Schema for its
 * data structure, validation logic, and HTML rendering.
 * @api
 */
#[Api(since: '1.0.0')]
interface BlockTypeInterface
{
    /**
     * Unique block type identifier (e.g., 'paragraph', 'heading').
     */
    public function type(): string;

    /**
     * JSON Schema describing the block's data structure.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Render the block to an HTML string.
     *
     * All user-provided text MUST be escaped with htmlspecialchars().
     *
     * @param array<string, mixed> $data
     */
    public function render(array $data): string;

    /**
     * Validate the block's data payload.
     *
     * @param array<string, mixed> $data
     * @return list<string> List of validation error messages (empty = valid)
     */
    public function validate(array $data): array;
}
