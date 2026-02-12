<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Attribute;

use Pulsar\Api\Api;

use function file_exists;
use function is_array;

/**
 * Loads pre-compiled validation metadata from a PHP array artifact.
 *
 * At runtime this class loads the artifact without reflection.
 * The artifact is produced by ValidationCompiler at build time.
 */
#[Api(since: '1.0.0')]
final readonly class CompiledValidationMap
{
    /**
     * @var array<class-string, array{
     *     rules: array<string, list<array{rule: class-string, parameters: array<string, mixed>, groups: list<string>}>>,
     *     filters: array<string, list<array{filter: class-string, parameters: array<string, mixed>}>>
     * }>
     */
    private array $map;

    public function __construct(string $artifactPath)
    {
        if (!file_exists($artifactPath)) {
            $this->map = [];

            return;
        }

        $loaded = require $artifactPath;

        /** @var array<class-string, array{rules: array<string, list<array{rule: class-string, parameters: array<string, mixed>, groups: list<string>}>>, filters: array<string, list<array{filter: class-string, parameters: array<string, mixed>}>>}> $validated */
        $validated = is_array($loaded) ? $loaded : [];
        $this->map = $validated;
    }

    /**
     * Whether compiled metadata exists for the given class.
     *
     * @param class-string $class
     */
    public function has(string $class): bool
    {
        return isset($this->map[$class]);
    }

    /**
     * Get rule definitions for a class.
     *
     * @param class-string $class
     *
     * @return array<string, list<array{rule: class-string, parameters: array<string, mixed>, groups: list<string>}>>
     */
    public function rulesFor(string $class): array
    {
        return $this->map[$class]['rules'] ?? [];
    }

    /**
     * Get filter definitions for a class.
     *
     * @param class-string $class
     *
     * @return array<string, list<array{filter: class-string, parameters: array<string, mixed>}>>
     */
    public function filtersFor(string $class): array
    {
        return $this->map[$class]['filters'] ?? [];
    }
}
