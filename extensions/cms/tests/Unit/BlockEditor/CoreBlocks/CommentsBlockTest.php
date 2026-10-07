<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CommentsBlock;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(CommentsBlock::class)]
final class CommentsBlockTest extends TestCase
{
    private CommentsBlock $block;

    protected function setUp(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn('csrf-test-token');
        $this->block = new CommentsBlock($csrf);
    }

    public function testType(): void
    {
        self::assertSame('comments', $this->block->type());
    }

    public function testRenderWithComments(): void
    {
        $html = $this->block->render([
            'contentId' => 'page-1',
            'comments' => [
                [
                    'id' => 'c1',
                    'author' => 'Jane',
                    'content' => 'Great article!',
                    'date' => '2026-03-14',
                ],
            ],
        ]);

        self::assertStringContainsString('id="comments"', $html);
        self::assertStringContainsString('Comments (1)', $html);
        self::assertStringContainsString('>Jane</strong>', $html);
        self::assertStringContainsString('Great article!', $html);
        self::assertStringContainsString('2026-03-14', $html);
    }

    public function testRenderWithAvatars(): void
    {
        $html = $this->block->render([
            'contentId' => 'p1',
            'comments' => [
                ['id' => 'c1', 'author' => 'A', 'content' => 'Hi', 'date' => '2026-01-01', 'avatarUrl' => '/avatars/a.jpg'],
            ],
        ]);

        self::assertStringContainsString('src="/avatars/a.jpg"', $html);
    }

    public function testRenderEmptyComments(): void
    {
        $html = $this->block->render([
            'contentId' => 'p1',
            'comments' => [],
        ]);

        self::assertStringContainsString('No comments yet.', $html);
    }

    public function testRenderIncludesForm(): void
    {
        $html = $this->block->render([
            'contentId' => 'p1',
            'showForm' => true,
        ]);

        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('name="_csrf_token"', $html);
        self::assertStringContainsString('value="csrf-test-token"', $html);
        self::assertStringContainsString('name="author"', $html);
        self::assertStringContainsString('name="content"', $html);
    }

    public function testRenderHidesFormWhenDisabled(): void
    {
        $html = $this->block->render([
            'contentId' => 'p1',
            'showForm' => false,
        ]);

        self::assertStringNotContainsString('<form', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'contentId' => '"><script>',
            'comments' => [
                ['id' => 'c1', 'author' => '<script>xss</script>', 'content' => '<img src=x>', 'date' => 'now'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresContentId(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('contentId is required and must be a string', $errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'contentId' => 'page-1',
            'comments' => [
                ['id' => 'c1', 'author' => 'Test', 'content' => 'Hi', 'date' => '2026-01-01'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
