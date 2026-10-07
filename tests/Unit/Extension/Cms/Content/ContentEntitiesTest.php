<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\ContentTypeRegistry;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\FieldDiff;
use Pulsar\Extension\Cms\Content\PathRecomputeResult;
use Pulsar\Extension\Cms\Content\Redirect;

#[CoversClass(ContentBlock::class)]
#[CoversClass(ContentTypeRegistry::class)]
#[CoversClass(FieldDiff::class)]
#[CoversClass(PathRecomputeResult::class)]
#[CoversClass(Redirect::class)]
final class ContentEntitiesTest extends TestCase
{
    // -- ContentType ----------------------------------------------------------

    #[Test]
    public function contentTypeValues(): void
    {
        self::assertSame('article', ContentType::Article->value);
        self::assertSame('page', ContentType::Page->value);
    }

    // -- ContentTypeRegistry --------------------------------------------------

    #[Test]
    public function contentTypeRegistryBuiltInTypes(): void
    {
        self::assertTrue(ContentTypeRegistry::isValid('article'));
        self::assertTrue(ContentTypeRegistry::isValid('page'));
    }

    #[Test]
    public function contentTypeRegistryCustomType(): void
    {
        ContentTypeRegistry::register('portfolio');

        self::assertTrue(ContentTypeRegistry::isValid('portfolio'));
        self::assertFalse(ContentTypeRegistry::isValid('nonexistent'));

        ContentTypeRegistry::reset();

        self::assertFalse(ContentTypeRegistry::isValid('portfolio'));
    }

    // -- CommentPolicy --------------------------------------------------------

    #[Test]
    public function commentPolicyValues(): void
    {
        self::assertSame('open', CommentPolicy::Open->value);
        self::assertSame('moderated', CommentPolicy::Moderated->value);
        self::assertSame('closed', CommentPolicy::Closed->value);
        self::assertSame('inherit', CommentPolicy::Inherit->value);
    }

    // -- DataClassification ---------------------------------------------------

    #[Test]
    public function dataClassificationValues(): void
    {
        self::assertSame('public', DataClassification::Public->value);
        self::assertSame('internal', DataClassification::Internal->value);
        self::assertSame('confidential', DataClassification::Confidential->value);
        self::assertSame('pii', DataClassification::Pii->value);
    }

    // -- ContentBlock ---------------------------------------------------------

    #[Test]
    public function contentBlockTextFactory(): void
    {
        $block = ContentBlock::text('blk-01', 'cnt-01', 'en', 0, ['content' => 'Hello world']);

        self::assertSame('blk-01', $block->id);
        self::assertSame('cnt-01', $block->contentId);
        self::assertSame('en', $block->locale);
        self::assertSame('text', $block->blockType);
        self::assertSame(0, $block->sortOrder);
        self::assertSame('Hello world', $block->data['content']);
    }

    #[Test]
    public function contentBlockImageFactory(): void
    {
        $block = ContentBlock::image('blk-02', 'cnt-01', 'en', 1, [
            'media_id' => 'media-01',
            'alt' => 'Hero image',
        ]);

        self::assertSame('image', $block->blockType);
        self::assertSame('media-01', $block->data['media_id']);
    }

    #[Test]
    public function contentBlockGalleryFactory(): void
    {
        $block = ContentBlock::gallery('blk-03', 'cnt-01', 'en', 2, [
            'media_ids' => ['media-01', 'media-02'],
            'layout' => 'grid',
        ]);

        self::assertSame('gallery', $block->blockType);
        $mediaIds = $block->data['media_ids'];
        self::assertIsArray($mediaIds);
        self::assertCount(2, $mediaIds);
    }

    #[Test]
    public function contentBlockCodeFactory(): void
    {
        $block = ContentBlock::code('blk-04', 'cnt-01', 'en', 3, [
            'code' => '<?php echo "Hello";',
            'language' => 'php',
        ]);

        self::assertSame('code', $block->blockType);
    }

    #[Test]
    public function contentBlockEmbedFactory(): void
    {
        $block = ContentBlock::embed('blk-05', 'cnt-01', 'en', 4, [
            'url' => 'https://youtube.com/watch?v=abc',
            'provider' => 'youtube',
        ]);

        self::assertSame('embed', $block->blockType);
    }

    #[Test]
    public function contentBlockHtmlFactory(): void
    {
        $block = ContentBlock::html('blk-06', 'cnt-01', 'en', 5, [
            'html' => '<div class="custom">Custom HTML</div>',
        ]);

        self::assertSame('html', $block->blockType);
    }

    #[Test]
    public function contentBlockCtaFactory(): void
    {
        $block = ContentBlock::cta('blk-07', 'cnt-01', 'en', 6, [
            'label' => 'Sign Up Now',
            'url' => '/signup',
            'style' => 'primary',
        ]);

        self::assertSame('cta', $block->blockType);
        self::assertSame('Sign Up Now', $block->data['label']);
    }

    // -- FieldDiff ------------------------------------------------------------

    #[Test]
    public function fieldDiffConstructor(): void
    {
        $diff = new FieldDiff(
            field: 'title',
            from: 'Old Title',
            to: 'New Title',
        );

        self::assertSame('title', $diff->field);
        self::assertSame('Old Title', $diff->from);
        self::assertSame('New Title', $diff->to);
    }

    #[Test]
    public function fieldDiffWithNullValues(): void
    {
        $diff = new FieldDiff(
            field: 'excerpt',
            from: null,
            to: 'New excerpt text',
        );

        self::assertNull($diff->from);
        self::assertSame('New excerpt text', $diff->to);
    }

    // -- PathRecomputeResult --------------------------------------------------

    #[Test]
    public function pathRecomputeResultConstructor(): void
    {
        $result = new PathRecomputeResult(
            descendantsUpdated: 15,
            redirectsCreated: 15,
        );

        self::assertSame(15, $result->descendantsUpdated);
        self::assertSame(15, $result->redirectsCreated);
    }

    // -- Redirect -------------------------------------------------------------

    #[Test]
    public function redirectConstructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T10:00:00+00:00');

        $redirect = new Redirect(
            id: 'redir-01',
            tenantId: 'tenant-01',
            fromPath: '/old-page',
            toPath: '/new-page',
            statusCode: 301,
            locale: 'en',
            hits: 42,
            lastHitAt: $now,
            createdAt: $now,
            createdBy: 'user-admin',
            reason: 'Page restructuring',
        );

        self::assertSame('redir-01', $redirect->id);
        self::assertSame('/old-page', $redirect->fromPath);
        self::assertSame('/new-page', $redirect->toPath);
        self::assertSame(301, $redirect->statusCode);
        self::assertSame('en', $redirect->locale);
        self::assertSame(42, $redirect->hits);
        self::assertSame('Page restructuring', $redirect->reason);
        self::assertNull($redirect->deletedAt);
    }

    #[Test]
    public function redirectSoftDeleted(): void
    {
        $now = new DateTimeImmutable();

        $redirect = new Redirect(
            id: 'redir-02',
            tenantId: null,
            fromPath: '/removed',
            toPath: '/target',
            statusCode: 308,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'user-01',
            reason: 'Temporary redirect',
            deletedAt: $now,
        );

        self::assertNotNull($redirect->deletedAt);
        self::assertNull($redirect->locale);
        self::assertNull($redirect->lastHitAt);
    }
}
