<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\TemplateInheritance;
use Pulsar\View\ViewException;

/**
 * Edge cases for TemplateInheritance not covered by the primary test.
 */
#[CoversClass(TemplateInheritance::class)]
final class TemplateInheritanceEdgeTest extends TestCase
{
    #[Test]
    public function inlineSectionSetsContentWithoutBuffering(): void
    {
        $env = new TemplateInheritance();
        $env->startSection('title', 'Inline Title');

        self::assertSame('Inline Title', $env->yieldSection('title'));
    }

    #[Test]
    public function inlineSectionFirstDefinitionWins(): void
    {
        $env = new TemplateInheritance();
        $env->startSection('title', 'First');
        $env->startSection('title', 'Second');

        self::assertSame('First', $env->yieldSection('title'));
    }

    #[Test]
    public function renderWithInheritanceThrowsWithoutCallback(): void
    {
        $env = new TemplateInheritance();
        $env->setParent('layouts.base');

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/render callback/');

        (void) $env->renderWithInheritance('child output');
    }

    #[Test]
    public function renderComponentThrowsWithoutCallback(): void
    {
        $env = new TemplateInheritance();
        $env->startComponent('components.alert');
        echo 'content';

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/render callback/');

        (void) $env->renderComponent();
    }

    #[Test]
    public function multiLevelInheritanceRendersCorrectly(): void
    {
        $env = new TemplateInheritance();

        // Simulate a two-level chain: child -> middle -> base
        $callCount = 0;
        $env->setRenderCallback(function (string $template, array $data) use ($env, &$callCount): string {
            $callCount++;

            if ($template === 'layouts.middle') {
                $env->setParent('layouts.base');

                return 'MIDDLE[' . $env->yieldSection('content') . ']';
            }

            if ($template === 'layouts.base') {
                return 'BASE[' . $env->yieldSection('content') . ']';
            }

            return '';
        });

        $env->setParent('layouts.middle');
        $env->startSection('content');
        echo 'Child Content';
        $env->endSection();

        $result = $env->renderWithInheritance('');

        self::assertSame('BASE[Child Content]', $result);
        self::assertSame(2, $callCount);
    }

    #[Test]
    public function resetClearsComponentAndSlotStacks(): void
    {
        $env = new TemplateInheritance();
        $env->setRenderCallback(fn(string $t, array $d): string => 'rendered');
        $env->setParent('some.template');

        $env->startSection('test');
        echo 'content';
        $env->endSection();

        $env->reset();

        self::assertNull($env->getParent());
        self::assertFalse($env->hasSection('test'));
    }

    #[Test]
    public function endSlotWithoutComponentAfterSlotStartThrows(): void
    {
        $env = new TemplateInheritance();
        $env->startSlot('orphan');
        echo 'orphan content';

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/no enclosing @component/');

        $env->endSlot();
    }

    #[Test]
    public function componentWithMultipleNamedSlots(): void
    {
        $env = new TemplateInheritance();
        $env->setRenderCallback(function (string $t, array $data): string {
            /** @var array<string, string> $data */
            return 'HEADER:' . ($data['header'] ?? '') . '|FOOTER:' . ($data['footer'] ?? '') . '|DEFAULT:' . ($data['slot'] ?? '');
        });

        $env->startComponent('components.layout');

        $env->startSlot('header');
        echo 'MyHeader';
        $env->endSlot();

        $env->startSlot('footer');
        echo 'MyFooter';
        $env->endSlot();

        echo 'DefaultSlotContent';

        $result = $env->renderComponent();

        self::assertSame('HEADER:MyHeader|FOOTER:MyFooter|DEFAULT:DefaultSlotContent', $result);
    }
}
