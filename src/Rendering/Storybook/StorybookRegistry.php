<?php

declare(strict_types=1);

namespace Pulsar\Rendering\Storybook;

use NoDiscard;
use Pulsar\Api\Api;

use function array_keys;
use function array_values;
use function count;

/**
 * Registry of component stories for the development environment.
 *
 * Collects all component stories and organizes them by category
 * for browsing in Pulsar Studio or standalone storybook server.
 */
#[Api(since: '1.0.0')]
final class StorybookRegistry
{
    /** @var array<string, list<ComponentStory>> Stories grouped by category */
    private array $stories = [];

    /**
     * Register a component story.
     */
    public function add(ComponentStory $story): void
    {
        $this->stories[$story->category][] = $story;
    }

    /**
     * Get all stories, grouped by category.
     *
     * @return array<string, list<ComponentStory>>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->stories;
    }

    /**
     * Get stories for a specific category.
     *
     * @return list<ComponentStory>
     */
    #[NoDiscard]
    public function forCategory(string $category): array
    {
        return $this->stories[$category] ?? [];
    }

    /**
     * Get all category names.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function categories(): array
    {
        return array_keys($this->stories);
    }

    /**
     * Get all stories as a flat list.
     *
     * @return list<ComponentStory>
     */
    #[NoDiscard]
    public function flat(): array
    {
        return array_merge(...array_values($this->stories));
    }

    /**
     * Total number of stories.
     */
    public function count(): int
    {
        $total = 0;

        foreach ($this->stories as $stories) {
            $total += count($stories);
        }

        return $total;
    }

    /**
     * Find a story by name.
     */
    public function find(string $name): ?ComponentStory
    {
        foreach ($this->stories as $stories) {
            foreach ($stories as $story) {
                if ($story->name === $name) {
                    return $story;
                }
            }
        }

        return null;
    }
}
