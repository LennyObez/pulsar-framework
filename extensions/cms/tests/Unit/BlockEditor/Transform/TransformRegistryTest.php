<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\Transform;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\Transform\BlockTransform;
use Pulsar\Extension\Cms\BlockEditor\Transform\CoreTransforms;
use Pulsar\Extension\Cms\BlockEditor\Transform\TransformRegistry;

#[CoversClass(TransformRegistry::class)]
#[CoversClass(BlockTransform::class)]
#[CoversClass(CoreTransforms::class)]
final class TransformRegistryTest extends TestCase
{
    private TransformRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TransformRegistry();
    }

    public function testRegisterAndFind(): void
    {
        $transform = new BlockTransform('heading', 'paragraph', fn(array $d) => ['text' => $d['text'] ?? '']);

        $this->registry->register($transform);

        self::assertSame($transform, $this->registry->find('heading', 'paragraph'));
    }

    public function testFindReturnsNullForUnknown(): void
    {
        self::assertNull($this->registry->find('heading', 'paragraph'));
    }

    public function testFromTypeReturnsAllTransforms(): void
    {
        $this->registry->register(new BlockTransform('heading', 'paragraph', fn(array $d) => $d));
        $this->registry->register(new BlockTransform('heading', 'quote', fn(array $d) => $d));

        $transforms = $this->registry->fromType('heading');

        self::assertCount(2, $transforms);
    }

    public function testAvailableTargets(): void
    {
        $this->registry->register(new BlockTransform('paragraph', 'heading', fn(array $d) => $d));
        $this->registry->register(new BlockTransform('paragraph', 'list', fn(array $d) => $d));
        $this->registry->register(new BlockTransform('paragraph', 'quote', fn(array $d) => $d));

        $targets = $this->registry->availableTargets('paragraph');

        self::assertSame(['heading', 'list', 'quote'], $targets);
    }

    public function testApplyTransformsData(): void
    {
        $this->registry->register(new BlockTransform(
            'heading',
            'paragraph',
            fn(array $d) => ['text' => $d['text'] ?? ''],
        ));

        $result = $this->registry->apply('heading', 'paragraph', ['text' => 'Hello', 'level' => 2]);

        self::assertNotNull($result);
        self::assertSame('paragraph', $result['type']);
        self::assertSame('Hello', $result['data']['text']);
        self::assertArrayNotHasKey('level', $result['data']);
    }

    public function testApplyReturnsNullForUnknownTransform(): void
    {
        self::assertNull($this->registry->apply('heading', 'video', ['text' => 'test']));
    }

    public function testCoreTransformsHeadingToParagraph(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('heading', 'paragraph', ['text' => 'My Title', 'level' => 1]);

        self::assertNotNull($result);
        self::assertSame('paragraph', $result['type']);
        self::assertSame('My Title', $result['data']['text']);
    }

    public function testCoreTransformsParagraphToHeading(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('paragraph', 'heading', ['text' => 'Promoted text']);

        self::assertNotNull($result);
        self::assertSame('heading', $result['type']);
        self::assertSame('Promoted text', $result['data']['text']);
        self::assertSame(2, $result['data']['level']);
    }

    public function testCoreTransformsListToParagraph(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('list', 'paragraph', [
            'items' => ['First', 'Second', 'Third'],
            'ordered' => false,
        ]);

        self::assertNotNull($result);
        /** @var string $text */
        $text = $result['data']['text'];
        self::assertStringContainsString('First', $text);
        self::assertStringContainsString('Third', $text);
    }

    public function testCoreTransformsParagraphToList(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('paragraph', 'list', ['text' => "Line 1\nLine 2\nLine 3"]);

        self::assertNotNull($result);
        self::assertSame('list', $result['type']);
        /** @var list<string> $items */
        $items = $result['data']['items'];
        self::assertCount(3, $items);
        self::assertFalse($result['data']['ordered']);
    }

    public function testCoreTransformsQuoteToParagraph(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('quote', 'paragraph', ['text' => 'Quoted text']);

        self::assertNotNull($result);
        self::assertSame('Quoted text', $result['data']['text']);
    }

    public function testCoreTransformsParagraphToQuote(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('paragraph', 'quote', ['text' => 'Notable statement']);

        self::assertNotNull($result);
        self::assertSame('quote', $result['type']);
        self::assertSame('Notable statement', $result['data']['text']);
    }

    public function testCoreTransformsHeadingToQuote(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('heading', 'quote', ['text' => 'A heading', 'level' => 3]);

        self::assertNotNull($result);
        self::assertSame('quote', $result['type']);
        self::assertSame('A heading', $result['data']['text']);
    }

    public function testCoreTransformsQuoteToHeading(): void
    {
        CoreTransforms::register($this->registry);

        $result = $this->registry->apply('quote', 'heading', ['text' => 'A quote']);

        self::assertNotNull($result);
        self::assertSame('heading', $result['type']);
        self::assertSame(2, $result['data']['level']);
    }
}
