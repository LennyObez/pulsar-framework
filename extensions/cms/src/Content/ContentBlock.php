<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Reusable content component for page builder semantics.
 *
 * Each block belongs to a content translation (content + locale) and carries
 * type-specific data as a validated JSON payload.
 *
 * @psalm-api Public DTO returned from ContentBlockRepositoryInterface; consumed
 *            by the block editor frontend bridge and template rendering.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ContentBlock
{
    /**
     * @param string $id UUIDv7
     * @param string $contentId UUIDv7 FK content
     * @param string $locale BCP 47 locale code
     * @param string $blockType Block type identifier (text, image, gallery, code, embed, html, cta, or plugin-registered)
     * @param int $sortOrder Position within content
     * @param array<string, mixed> $data Block-type-specific payload
     * @param DateTimeImmutable $createdAt Creation timestamp
     * @param DateTimeImmutable $updatedAt Update timestamp
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public string $locale,
        public string $blockType,
        public int $sortOrder,
        public array $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a text block.
     *
     * @param array{content: string} $data
     */
    public static function text(string $id, string $contentId, string $locale, int $sortOrder, array $data): self
    {
        return self::create($id, $contentId, $locale, 'text', $sortOrder, $data);
    }

    /**
     * Create an image block.
     *
     * @param array{media_id: string, alt?: string, caption?: string} $data
     */
    public static function image(string $id, string $contentId, string $locale, int $sortOrder, array $data): self
    {
        return self::create($id, $contentId, $locale, 'image', $sortOrder, $data);
    }

    /**
     * Create a gallery block.
     *
     * @param array{media_ids: list<string>, layout?: string} $data
     */
    public static function gallery(string $id, string $contentId, string $locale, int $sortOrder, array $data): self
    {
        return self::create($id, $contentId, $locale, 'gallery', $sortOrder, $data);
    }

    /**
     * Create a code block.
     *
     * @param array{code: string, language?: string} $data
     */
    public static function code(string $id, string $contentId, string $locale, int $sortOrder, array $data): self
    {
        return self::create($id, $contentId, $locale, 'code', $sortOrder, $data);
    }

    /**
     * Create an embed block.
     *
     * @param array{url: string, provider?: string} $data
     */
    public static function embed(string $id, string $contentId, string $locale, int $sortOrder, array $data): self
    {
        return self::create($id, $contentId, $locale, 'embed', $sortOrder, $data);
    }

    /**
     * Create an HTML block.
     *
     * @param array{html: string} $data
     */
    public static function html(string $id, string $contentId, string $locale, int $sortOrder, array $data): self
    {
        return self::create($id, $contentId, $locale, 'html', $sortOrder, $data);
    }

    /**
     * Create a call-to-action block.
     *
     * @param array{label: string, url: string, style?: string} $data
     */
    public static function cta(string $id, string $contentId, string $locale, int $sortOrder, array $data): self
    {
        return self::create($id, $contentId, $locale, 'cta', $sortOrder, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function create(
        string $id,
        string $contentId,
        string $locale,
        string $blockType,
        int $sortOrder,
        array $data,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            contentId: $contentId,
            locale: $locale,
            blockType: $blockType,
            sortOrder: $sortOrder,
            data: $data,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
