<?php

declare(strict_types=1);

namespace Pulsar\Container\Tag;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Container\ServiceDefinition;

use function usort;

/**
 * Collects and sorts service definitions by tag name.
 *
 * Filters definitions by tag name and sorts by (priority DESC, id ASC)
 * for deterministic ordering.
 */
#[Api(since: '1.0.0')]
final class TagCollector
{
    /**
     * Collect all definitions tagged with the given tag name.
     *
     * @param string $tagName Tag name to filter by
     * @param array<string, ServiceDefinition> $definitions All service definitions
     * @return list<ServiceDefinition> Definitions sorted by (priority DESC, id ASC)
     */
    #[NoDiscard]
    public static function collect(string $tagName, array $definitions): array
    {
        $matches = self::matchByTag($tagName, $definitions);

        $result = [];
        foreach ($matches as $entry) {
            $result[] = $entry['definition'];
        }

        return $result;
    }

    /**
     * Collect all service IDs tagged with the given tag name.
     *
     * @param string $tagName Tag name to filter by
     * @param array<string, ServiceDefinition> $definitions All service definitions
     * @return list<string> Service IDs sorted by (priority DESC, id ASC)
     */
    #[NoDiscard]
    public static function collectIds(string $tagName, array $definitions): array
    {
        $matches = self::matchByTag($tagName, $definitions);

        $result = [];
        foreach ($matches as $entry) {
            $result[] = $entry['id'];
        }

        return $result;
    }

    /**
     * Match definitions by tag name and sort by (priority DESC, id ASC).
     *
     * @param array<string, ServiceDefinition> $definitions
     * @return list<array{id: string, definition: ServiceDefinition, priority: int}>
     */
    private static function matchByTag(string $tagName, array $definitions): array
    {
        $matches = [];

        foreach ($definitions as $id => $definition) {
            foreach ($definition->tags as $tag) {
                if ($tag->name === $tagName) {
                    $matches[] = ['id' => $id, 'definition' => $definition, 'priority' => $tag->priority];
                    break;
                }
            }
        }

        usort($matches, static function (array $a, array $b): int {
            $priorityDiff = $b['priority'] <=> $a['priority'];

            return $priorityDiff !== 0 ? $priorityDiff : $a['id'] <=> $b['id'];
        });

        return $matches;
    }
}
