<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\ContentBlock;

use function count;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_string;

use const ENT_QUOTES;

/**
 * Renders a list of content blocks to an HTML string using the block type registry.
 *
 * Unknown block types are rendered as HTML comments. Blocks that fail validation
 * are rendered as HTML comments containing the error details.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BlockRenderer
{
    public function __construct(
        private BlockTypeRegistry $registry,
    ) {}

    /**
     * Render a list of content blocks to a concatenated HTML string.
     *
     * @param list<ContentBlock> $blocks Ordered list of content blocks
     * @param string|null $cspNonce Request CSP nonce, exposed to blocks via the
     *        `_csp_nonce` render-context key so script-emitting blocks (e.g. the
     *        contact form's managed-challenge widget) can stamp it.
     */
    public function render(array $blocks, ?string $cspNonce = null): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $blockType = $this->registry->get($block->blockType);

            if ($blockType === null) {
                $escapedType = htmlspecialchars($block->blockType, ENT_QUOTES, 'UTF-8');
                $parts[] = "<!-- unknown block type: $escapedType -->";

                continue;
            }

            $errors = $blockType->validate($block->data);

            if (count($errors) > 0) {
                $escapedType = htmlspecialchars($block->blockType, ENT_QUOTES, 'UTF-8');
                $errorList = htmlspecialchars(implode('; ', $errors), ENT_QUOTES, 'UTF-8');
                $parts[] = "<!-- block validation error ($escapedType): $errorList -->";

                continue;
            }

            $parts[] = $blockType->render(self::withNonce($block->data, $cspNonce));
        }

        return implode("\n", $parts);
    }

    /**
     * Render raw block data without requiring ContentBlock wrappers.
     *
     * Used by composite blocks (e.g., ColumnsBlock) that need to render
     * nested blocks from raw data arrays without fabricating identity fields.
     *
     * @param list<mixed> $blocks
     * @param string|null $cspNonce Request CSP nonce, propagated to each block via
     *        the `_csp_nonce` render-context key (see {@see render()}).
     */
    public function renderRawBlocks(array $blocks, ?string $cspNonce = null): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $blockTypeName = $block['blockType'] ?? '';

            if (!is_string($blockTypeName) || $blockTypeName === '') {
                continue;
            }

            $blockType = $this->registry->get($blockTypeName);

            if ($blockType === null) {
                $escapedType = htmlspecialchars($blockTypeName, ENT_QUOTES, 'UTF-8');
                $parts[] = "<!-- unknown block type: $escapedType -->";

                continue;
            }

            /** @var array<string, mixed> $data */
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $errors = $blockType->validate($data);

            if (count($errors) > 0) {
                $escapedType = htmlspecialchars($blockTypeName, ENT_QUOTES, 'UTF-8');
                $errorList = htmlspecialchars(implode('; ', $errors), ENT_QUOTES, 'UTF-8');
                $parts[] = "<!-- block validation error ($escapedType): $errorList -->";

                continue;
            }

            $parts[] = $blockType->render(self::withNonce($data, $cspNonce));
        }

        return implode("\n", $parts);
    }

    /**
     * Expose the request CSP nonce to a block through the `_csp_nonce` render
     * key, leaving the block data untouched when no nonce is available.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function withNonce(array $data, ?string $cspNonce): array
    {
        if ($cspNonce === null || $cspNonce === '') {
            return $data;
        }

        return [...$data, '_csp_nonce' => $cspNonce];
    }
}
