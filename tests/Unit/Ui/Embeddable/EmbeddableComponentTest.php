<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\EmbeddableComponent;

#[CoversClass(EmbeddableComponent::class)]
final class EmbeddableComponentTest extends TestCase
{
    #[Test]
    public function renderOutputsCustomElement(): void
    {
        $component = $this->createConcreteComponent('pulsar-test', '<span>inner</span>');

        $html = $component->render();

        self::assertStringContainsString('<pulsar-test>', $html);
        self::assertStringContainsString('</pulsar-test>', $html);
        self::assertStringContainsString('<span>inner</span>', $html);
    }

    #[Test]
    public function propSetsValueAndReturnsStatic(): void
    {
        $component = $this->createConcreteComponent('pulsar-btn', '<b>OK</b>');

        $result = $component->prop('label', 'Click');

        self::assertSame($component, $result);
    }

    #[Test]
    public function propsAreSerializedAsDataAttribute(): void
    {
        $component = $this->createConcreteComponent('pulsar-widget', '<div></div>');
        $component->prop('count', 5);
        $component->prop('title', 'Hello');

        $html = $component->render();

        self::assertStringContainsString('data-props=', $html);
        self::assertStringContainsString('count', $html);
        self::assertStringContainsString('Hello', $html);
    }

    #[Test]
    public function attrSetsHtmlAttributeAndReturnsStatic(): void
    {
        $component = $this->createConcreteComponent('pulsar-box', '<p>hi</p>');

        $result = $component->attr('id', 'my-box');

        self::assertSame($component, $result);

        $html = $component->render();
        self::assertStringContainsString('id="my-box"', $html);
    }

    #[Test]
    public function renderWithoutPropsHasNoDataPropsAttribute(): void
    {
        $component = $this->createConcreteComponent('pulsar-empty', '');

        $html = $component->render();

        self::assertStringNotContainsString('data-props', $html);
    }

    #[Test]
    public function renderEscapesAttributeValues(): void
    {
        $component = $this->createConcreteComponent('pulsar-safe', '');
        $component->attr('title', 'He said "hello" & goodbye');

        $html = $component->render();

        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('&quot;', $html);
    }

    #[Test]
    public function stylesDefaultsToEmpty(): void
    {
        $component = $this->createConcreteComponent('pulsar-x', '');

        self::assertSame('', $component->styles());
    }

    #[Test]
    public function scriptDefaultsToEmpty(): void
    {
        $component = $this->createConcreteComponent('pulsar-x', '');

        self::assertSame('', $component->script());
    }

    private function createConcreteComponent(string $tag, string $inner): EmbeddableComponent
    {
        return new class ($tag, $inner) extends EmbeddableComponent {
            public function __construct(
                private readonly string $tag,
                private readonly string $inner,
            ) {}

            public function tagName(): string
            {
                return $this->tag;
            }

            public function renderInner(): string
            {
                return $this->inner;
            }
        };
    }
}
