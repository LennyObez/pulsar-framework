<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Computes hierarchical URL paths and enforces path integrity invariants.
 *
 * Handles cycle detection, max depth enforcement, and cascading path
 * recomputation when content hierarchy changes.
 *
 * @psalm-api Resolved by content service from the DI container; not new'd by name.
 */
#[Internal]
final readonly class PathComputer
{
    /**
     * Compute the full path from a parent path and a slug segment.
     *
     * @param string|null $parentPath Parent content's full path, null for root content
     * @param string $slugSegment This content's slug segment
     */
    public function computePath(?string $parentPath, string $slugSegment): string
    {
        if ($parentPath !== null && $parentPath !== '') {
            return $parentPath . '/' . $slugSegment;
        }

        return $slugSegment;
    }

    /**
     * Detect whether setting a parent would create a cycle in the hierarchy.
     *
     * Walks the ancestor chain from the proposed parent up to root.
     * If the content's own ID appears in the chain, a cycle exists.
     *
     * @return bool True if a cycle would be created
     */
    public function detectCycle(string $contentId, string $proposedParentId, ConnectionInterface $db): bool
    {
        if ($contentId === $proposedParentId) {
            return true;
        }

        $result = $db->query(
            <<<'SQL'
                WITH RECURSIVE ancestors AS (
                    SELECT id, parent_id FROM cms_contents WHERE id = :parent_id
                    UNION ALL
                    SELECT c.id, c.parent_id FROM cms_contents c
                    JOIN ancestors a ON c.id = a.parent_id
                )
                SELECT id FROM ancestors WHERE id = :content_id LIMIT 1
                SQL,
            [
                'parent_id' => $proposedParentId,
                'content_id' => $contentId,
            ],
        );

        return !$result->isEmpty();
    }

    /**
     * Enforce maximum hierarchy depth by counting ancestors of the proposed parent.
     *
     * @return bool True if adding a child under proposedParentId would exceed maxDepth
     */
    public function enforceMaxDepth(string $proposedParentId, int $maxDepth, ConnectionInterface $db): bool
    {
        $result = $db->query(
            <<<'SQL'
                WITH RECURSIVE ancestors AS (
                    SELECT id, parent_id, 1 AS depth FROM cms_contents WHERE id = :parent_id
                    UNION ALL
                    SELECT c.id, c.parent_id, a.depth + 1 FROM cms_contents c
                    JOIN ancestors a ON c.id = a.parent_id
                )
                SELECT MAX(depth) AS max_depth FROM ancestors
                SQL,
            ['parent_id' => $proposedParentId],
        );

        $row = $result->first();

        if ($row === null) {
            return false;
        }

        $currentDepth = $row->getNullableInt('max_depth');

        // Adding a child would be depth + 1
        return $currentDepth !== null && ($currentDepth + 1) > $maxDepth;
    }

    /**
     * Recompute paths for all descendants after a content item's slug or parent changes.
     *
     * Uses a recursive CTE to find all descendants, then recomputes paths
     * breadth-first. Returns a list of path changes for redirect creation.
     *
     * @return list<array{contentId: string, locale: string, oldPath: string, newPath: string}>
     */
    public function recomputeDescendantPaths(
        string $contentId,
        string $locale,
        ConnectionInterface $db,
    ): array {
        // Load the changed content's new path as the starting point
        $parentResult = $db->query(
            'SELECT path FROM cms_content_translations WHERE content_id = :id AND locale = :locale LIMIT 1',
            ['id' => $contentId, 'locale' => $locale],
        );

        $parentRow = $parentResult->first();

        if ($parentRow === null) {
            return [];
        }

        // Get descendants with their current paths, breadth-first
        $result = $db->query(
            <<<'SQL'
                WITH RECURSIVE descendants AS (
                    SELECT c.id, c.parent_id, 1 AS depth
                    FROM cms_contents c
                    WHERE c.parent_id = :content_id
                    UNION ALL
                    SELECT c.id, c.parent_id, d.depth + 1
                    FROM cms_contents c
                    JOIN descendants d ON c.parent_id = d.id
                )
                SELECT d.id, d.parent_id, d.depth,
                       ct.slug_segment, ct.path AS old_path
                FROM descendants d
                JOIN cms_content_translations ct ON ct.content_id = d.id AND ct.locale = :locale
                ORDER BY d.depth ASC
                SQL,
            [
                'content_id' => $contentId,
                'locale' => $locale,
            ],
        );

        if ($result->isEmpty()) {
            return [];
        }

        // Build a map of contentId => newPath as we compute them
        /** @var array<string, string> $pathMap */
        $pathMap = [];
        $pathMap[$contentId] = $parentRow->getString('path');

        /** @var list<array{contentId: string, locale: string, oldPath: string, newPath: string}> $changes */
        $changes = [];

        foreach ($result->rows as $row) {
            $descendantId = $row->getString('id');
            $parentId = $row->getString('parent_id');
            $slugSegment = $row->getString('slug_segment');
            $oldPath = $row->getString('old_path');

            $parentPath = $pathMap[$parentId] ?? null;

            if ($parentPath === null) {
                continue;
            }

            $newPath = $this->computePath($parentPath, $slugSegment);
            $pathMap[$descendantId] = $newPath;

            if ($newPath !== $oldPath) {
                $changes[] = [
                    'contentId' => $descendantId,
                    'locale' => $locale,
                    'oldPath' => $oldPath,
                    'newPath' => $newPath,
                ];

                $db->execute(
                    'UPDATE cms_content_translations SET path = :new_path WHERE content_id = :id AND locale = :locale',
                    [
                        'new_path' => $newPath,
                        'id' => $descendantId,
                        'locale' => $locale,
                    ],
                );
            }
        }

        return $changes;
    }

    /**
     * Validate a parent assignment and throw on constraint violations.
     *
     * @throws CmsException On cycle detection or max depth exceeded
     */
    public function validateParentAssignment(
        string $contentId,
        string $proposedParentId,
        int $maxDepth,
        ConnectionInterface $db,
    ): void {
        if ($this->detectCycle($contentId, $proposedParentId, $db)) {
            throw CmsException::circularParentReference();
        }

        if ($this->enforceMaxDepth($proposedParentId, $maxDepth, $db)) {
            throw CmsException::maxDepthExceeded($maxDepth);
        }
    }
}
