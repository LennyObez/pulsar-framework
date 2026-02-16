<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\TemplateInheritance;
use Pulsar\View\ViewException;

#[CoversClass(TemplateInheritance::class)]
final class TemplateInheritanceTest extends TestCase
{
    private TemplateInheritance $env;

    protected function setUp(): void
    {
        $this->env = new TemplateInheritance();
    }

    // --- Section Tests ---

    #[Test]
    public function startAndEndSectionCapturesContent(): void
    {
        $this->env->startSection('title');
        echo 'Hello World';
        $this->env->endSection();

        self::assertSame('Hello World', $this->env->yieldSection('title'));
    }

    #[Test]
    public function yieldSectionReturnsDefaultWhenSectionNotDefined(): void
    {
        self::assertSame('', $this->env->yieldSection('missing'));
        self::assertSame('Default', $this->env->yieldSection('missing', 'Default'));
    }

    #[Test]
    public function hasSectionReturnsTrueForDefinedSection(): void
    {
        $this->env->startSection('sidebar');
        echo 'Sidebar content';
        $this->env->endSection();

        self::assertTrue($this->env->hasSection('sidebar'));
    }

    #[Test]
    public function hasSectionReturnsFalseForUndefinedSection(): void
    {
        self::assertFalse($this->env->hasSection('nonexistent'));
    }

    #[Test]
    public function firstSectionDefinitionWins(): void
    {
        // Child defines section first
        $this->env->startSection('content');
        echo 'Child Content';
        $this->env->endSection();

        // Parent tries to define same section (should be ignored)
        $this->env->startSection('content');
        echo 'Parent Content';
        $this->env->endSection();

        self::assertSame('Child Content', $this->env->yieldSection('content'));
    }

    #[Test]
    public function multipleSectionsCanBeDefined(): void
    {
        $this->env->startSection('header');
        echo 'Header';
        $this->env->endSection();

        $this->env->startSection('footer');
        echo 'Footer';
        $this->env->endSection();

        self::assertSame('Header', $this->env->yieldSection('header'));
        self::assertSame('Footer', $this->env->yieldSection('footer'));
    }

    #[Test]
    public function endSectionWithoutStartThrows(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/no matching @section/');

        $this->env->endSection();
    }

    // --- Parent/Extends Tests ---

    #[Test]
    public function setAndGetParent(): void
    {
        self::assertNull($this->env->getParent());

        $this->env->setParent('layouts.main');

        self::assertSame('layouts.main', $this->env->getParent());
    }

    #[Test]
    public function renderWithInheritanceReturnsChildOutputWhenNoParent(): void
    {
        $result = $this->env->renderWithInheritance('direct output');

        self::assertSame('direct output', $result);
    }

    #[Test]
    public function renderWithInheritanceRendersParentTemplate(): void
    {
        $this->env->setRenderCallback(function (string $template, array $data): string {
            if ($template === 'layouts.base') {
                return '<html>' . $this->env->yieldSection('content') . '</html>';
            }

            return '';
        });

        $this->env->setParent('layouts.base');
        $this->env->startSection('content');
        echo 'Page Content';
        $this->env->endSection();

        $result = $this->env->renderWithInheritance('');

        self::assertSame('<html>Page Content</html>', $result);
    }

    #[Test]
    public function renderWithInheritanceClearsParentAfterRender(): void
    {
        $this->env->setRenderCallback(fn(string $t, array $d): string => 'parent');
        $this->env->setParent('layouts.main');

        (void) $this->env->renderWithInheritance('');

        self::assertNull($this->env->getParent());
    }

    // --- Include Tests ---

    #[Test]
    public function renderIncludeCallsRenderCallback(): void
    {
        $this->env->setRenderCallback(fn(string $t, array $d): string => "Included: {$t}");

        $result = $this->env->renderInclude('partials.header');

        self::assertSame('Included: partials.header', $result);
    }

    #[Test]
    public function renderIncludePassesScopedData(): void
    {
        $this->env->setRenderCallback(function (string $t, array $data): string {
            /** @var string $active */
            $active = $data['active'] ?? 'none';

            return 'Active: ' . $active;
        });

        $result = $this->env->renderInclude('partials.nav', ['active' => 'home']);

        self::assertSame('Active: home', $result);
    }

    #[Test]
    public function renderIncludeThrowsWithoutCallback(): void
    {
        $this->expectException(ViewException::class);

        (void) $this->env->renderInclude('any.template');
    }

    // --- Component Tests ---

    #[Test]
    public function basicComponentRendering(): void
    {
        $this->env->setRenderCallback(function (string $t, array $data): string {
            /** @var string $slot */
            $slot = $data['slot'] ?? '';

            return '<div class="alert">' . $slot . '</div>';
        });

        $this->env->startComponent('components.alert');
        echo 'Alert message';
        $result = $this->env->renderComponent();

        self::assertSame('<div class="alert">Alert message</div>', $result);
    }

    #[Test]
    public function componentWithDataProps(): void
    {
        $this->env->setRenderCallback(function (string $t, array $data): string {
            /** @var string $type */
            $type = $data['type'] ?? 'info';
            /** @var string $slot */
            $slot = $data['slot'] ?? '';

            return "<div class=\"alert-{$type}\">" . $slot . '</div>';
        });

        $this->env->startComponent('components.alert', ['type' => 'danger']);
        echo 'Error!';
        $result = $this->env->renderComponent();

        self::assertSame('<div class="alert-danger">Error!</div>', $result);
    }

    #[Test]
    public function componentWithNamedSlots(): void
    {
        $this->env->setRenderCallback(function (string $t, array $data): string {
            /** @var string $title */
            $title = $data['title'] ?? 'No Title';
            /** @var string $body */
            $body = $data['slot'] ?? '';

            return "<div><h2>{$title}</h2><p>{$body}</p></div>";
        });

        $this->env->startComponent('components.card');

        $this->env->startSlot('title');
        echo 'Card Title';
        $this->env->endSlot();

        echo 'Card body content';

        $result = $this->env->renderComponent();

        self::assertSame('<div><h2>Card Title</h2><p>Card body content</p></div>', $result);
    }

    #[Test]
    public function endComponentWithoutStartThrows(): void
    {
        $this->expectException(ViewException::class);

        (void) $this->env->renderComponent();
    }

    #[Test]
    public function endSlotWithoutStartThrows(): void
    {
        $this->expectException(ViewException::class);

        $this->env->endSlot();
    }

    #[Test]
    public function endSlotWithoutComponentThrows(): void
    {
        $this->env->startSlot('orphan');
        echo 'content';

        $this->expectException(ViewException::class);

        $this->env->endSlot();
    }

    // --- Reset Tests ---

    #[Test]
    public function resetClearsAllState(): void
    {
        $this->env->setParent('layouts.main');

        $this->env->startSection('test');
        echo 'content';
        $this->env->endSection();

        $this->env->reset();

        self::assertNull($this->env->getParent());
        self::assertFalse($this->env->hasSection('test'));
        self::assertSame('', $this->env->yieldSection('test'));
    }
}
