<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Pattern;

use Pulsar\Api\Api;

use function array_key_exists;
use function array_values;
use function stripos;

/**
 * Registry for block patterns.
 *
 * Provides registration, lookup, and search functionality for
 * pre-built block compositions.
 * @api
 */
#[Api(since: '1.0.0')]
final class PatternRegistry
{
    /** @var array<string, BlockPattern> */
    private array $patterns = [];

    /**
     * Register a block pattern.
     */
    public function register(BlockPattern $pattern): void
    {
        $this->patterns[$pattern->name] = $pattern;
    }

    /**
     * Get a pattern by name.
     */
    public function get(string $name): ?BlockPattern
    {
        return $this->patterns[$name] ?? null;
    }

    /**
     * Check if a pattern exists.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->patterns);
    }

    /**
     * Get all patterns in a category.
     *
     * @return list<BlockPattern>
     */
    public function byCategory(string $category): array
    {
        $results = [];

        foreach ($this->patterns as $pattern) {
            if ($pattern->category === $category) {
                $results[] = $pattern;
            }
        }

        return $results;
    }

    /**
     * Search patterns by keyword or title.
     *
     * @return list<BlockPattern>
     */
    public function search(string $query): array
    {
        $results = [];

        foreach ($this->patterns as $pattern) {
            if (stripos($pattern->title, $query) !== false
                || stripos($pattern->description, $query) !== false) {
                $results[] = $pattern;
                continue;
            }

            foreach ($pattern->keywords as $keyword) {
                if (stripos($keyword, $query) !== false) {
                    $results[] = $pattern;
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Get all registered patterns.
     *
     * @return list<BlockPattern>
     */
    public function all(): array
    {
        return array_values($this->patterns);
    }

    /**
     * Get all unique categories.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->patterns as $pattern) {
            $categories[$pattern->category] = true;
        }

        return array_keys($categories);
    }
}
