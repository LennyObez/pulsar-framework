<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Closure;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewException;

use function array_key_exists;
use function array_pop;
use function count;
use function ob_get_clean;
use function ob_start;

/**
 * Runtime support for template inheritance and section management.
 *
 * Manages the parent template chain, section content, and @yield rendering.
 * Used as `$__env` in compiled templates.
 */
#[Internal(reason: 'Runtime inheritance tracking is an engine implementation detail')]
final class TemplateInheritance
{
    /** @var array<string, string> Section name → captured content */
    private array $sections = [];

    /** @var list<string> Stack of section names currently being captured */
    private array $sectionStack = [];

    /** The parent template name, set by @extends */
    private ?string $parent = null;

    /** @var list<array{name: string, data: array<string, mixed>}> Component stack */
    private array $componentStack = [];

    /** @var list<array{name: string}> Slot stack */
    private array $slotStack = [];

    /** @var array<string, array<string, string>> Component name → slot name → content */
    private array $componentSlots = [];

    /** Callback for rendering sub-templates (set by the engine) */
    private ?Closure $renderCallback = null;

    /**
     * Set the render callback used for @include and parent template rendering.
     *
     * @param Closure(string, array<string, mixed>): string $callback
     */
    public function setRenderCallback(Closure $callback): void
    {
        $this->renderCallback = $callback;
    }

    /**
     * Set the parent template (called by @extends).
     */
    public function setParent(string $template): void
    {
        $this->parent = $template;
    }

    /**
     * Get the parent template name.
     */
    #[NoDiscard]
    public function getParent(): ?string
    {
        return $this->parent;
    }

    /**
     * Start a new section (called by @section).
     */
    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    /**
     * End the current section (called by @endsection).
     */
    public function endSection(): void
    {
        if ($this->sectionStack === []) {
            throw ViewException::invalidDirective('endsection', 'no matching @section');
        }

        $name = array_pop($this->sectionStack);
        $content = (string) ob_get_clean();

        // First definition wins (child overrides parent)
        if (!array_key_exists($name, $this->sections)) {
            $this->sections[$name] = $content;
        }
    }

    /**
     * Yield a section's content (called by @yield).
     *
     * @param string $name Section name
     * @param string $default Default content if section is not defined
     */
    #[NoDiscard]
    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Check whether a section has been defined.
     */
    #[NoDiscard]
    public function hasSection(string $name): bool
    {
        return array_key_exists($name, $this->sections);
    }

    /**
     * Render an included sub-template (called by @include).
     *
     * @param string $template Template name
     * @param array<string, mixed> $data Scoped data for the included template
     */
    #[NoDiscard]
    public function renderInclude(string $template, array $data = []): string
    {
        if ($this->renderCallback === null) {
            throw ViewException::compilationFailed($template, 'no render callback set for @include');
        }

        /** @var string */
        return ($this->renderCallback)($template, $data);
    }

    /**
     * Render the parent template if one was set via @extends.
     *
     * Returns the combined output: child sections inserted into parent yields.
     *
     * @param string $childOutput The raw output of the child template
     */
    #[NoDiscard]
    public function renderWithInheritance(string $childOutput): string
    {
        if ($this->parent === null) {
            return $childOutput;
        }

        if ($this->renderCallback === null) {
            throw ViewException::compilationFailed(
                $this->parent,
                'no render callback set for @extends',
            );
        }

        $parentTemplate = $this->parent;
        $this->parent = null;

        /** @var string */
        return ($this->renderCallback)($parentTemplate, []);
    }

    // --- Component System ---

    /**
     * Start a component (called by @component).
     *
     * @param string $name Component template name
     * @param array<string, mixed> $data Component data/props
     */
    public function startComponent(string $name, array $data = []): void
    {
        $this->componentStack[] = ['name' => $name, 'data' => $data];
        $this->componentSlots[$name] = [];
        ob_start();
    }

    /**
     * Render and return the current component (called by @endcomponent).
     */
    #[NoDiscard]
    public function renderComponent(): string
    {
        if ($this->componentStack === []) {
            throw ViewException::invalidDirective('endcomponent', 'no matching @component');
        }

        $component = array_pop($this->componentStack);
        $defaultContent = (string) ob_get_clean();

        $name = $component['name'];
        $data = $component['data'];

        $slots = $this->componentSlots[$name] ?? [];
        unset($this->componentSlots[$name]);

        $data['slot'] = $defaultContent;

        foreach ($slots as $slotName => $slotContent) {
            $data[$slotName] = $slotContent;
        }

        if ($this->renderCallback === null) {
            throw ViewException::compilationFailed($name, 'no render callback set for @component');
        }

        /** @var string */
        return ($this->renderCallback)($name, $data);
    }

    /**
     * Start a named slot (called by @slot).
     */
    public function startSlot(string $name): void
    {
        $this->slotStack[] = ['name' => $name];
        ob_start();
    }

    /**
     * End the current slot (called by @endslot).
     */
    public function endSlot(): void
    {
        if ($this->slotStack === []) {
            throw ViewException::invalidDirective('endslot', 'no matching @slot');
        }

        $slot = array_pop($this->slotStack);
        $content = (string) ob_get_clean();

        if ($this->componentStack === []) {
            throw ViewException::invalidDirective('endslot', 'no enclosing @component');
        }

        $componentName = $this->componentStack[count($this->componentStack) - 1]['name'];

        if (!isset($this->componentSlots[$componentName])) {
            $this->componentSlots[$componentName] = [];
        }

        $this->componentSlots[$componentName][$slot['name']] = $content;
    }

    /**
     * Reset state between template renders.
     */
    public function reset(): void
    {
        $this->sections = [];
        $this->sectionStack = [];
        $this->parent = null;
        $this->componentStack = [];
        $this->slotStack = [];
        $this->componentSlots = [];
    }
}
