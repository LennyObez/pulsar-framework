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
use function implode;
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

    /** @var array<string, list<string>> Stack name → ordered contributions (@push) */
    private array $stacks = [];

    /** @var list<string> Names of @push blocks currently capturing (nesting) */
    private array $pushStack = [];

    /** The parent template name, set by @extends */
    private ?string $parent = null;

    /** @var list<array{name: string, data: array<string, mixed>}> Component stack */
    private array $componentStack = [];

    /** @var list<array{name: string}> Slot stack */
    private array $slotStack = [];

    /** @var array<int, array<string, string>> Component stack depth → slot name → content */
    private array $componentSlots = [];

    /** @var array<string, true> Identifiers of @once blocks already rendered this request */
    private array $onceIds = [];

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
     *
     * When called with a second argument (@section('name', 'content')), sets the
     * section inline without starting output buffering: no @endsection needed.
     */
    public function startSection(string $name, ?string $content = null): void
    {
        if ($content !== null) {
            // Inline section: @section('title', 'My Page Title')
            if (!array_key_exists($name, $this->sections)) {
                $this->sections[$name] = $content;
            }

            return;
        }

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
     * Begin capturing a @push block onto the named stack.
     *
     * Stacks live on the engine ($__env), like sections, so contributions
     * survive the @extends boundary — a child's @push reaches the parent
     * layout's @stack even though the parent renders in a separate pass.
     */
    public function startPush(string $name): void
    {
        $this->pushStack[] = $name;
        ob_start();
    }

    /**
     * Finish the current @push block, appending its content to the stack.
     *
     * @throws ViewException If there is no open @push.
     */
    public function stopPush(): void
    {
        if ($this->pushStack === []) {
            throw ViewException::invalidDirective('endpush', 'no matching @push');
        }

        $name = array_pop($this->pushStack);
        $this->stacks[$name][] = (string) ob_get_clean();
    }

    /**
     * Render a stack (called by @stack): all @push contributions, in order.
     *
     * Stacks live on $__env, so they survive the @extends boundary — the child
     * (which @pushes) always renders before the parent layout (which @stacks),
     * so by the time @stack runs the contributions are already present.
     */
    #[NoDiscard]
    public function renderStack(string $name): string
    {
        return implode('', $this->stacks[$name] ?? []);
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
     * Render the parent template chain if one was set via @extends.
     *
     * Handles multi-level inheritance (e.g. page → layout → base-layout)
     * by iterating until no more parents are declared.
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

        // Walk the inheritance chain: each parent may itself declare @extends
        $output = $childOutput;

        while ($this->parent !== null) {
            $parentTemplate = $this->parent;
            $this->parent = null;

            /** @var string $output */
            $output = ($this->renderCallback)($parentTemplate, []);
        }

        return $output;
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
        // Key slots by stack depth, not name, so nesting two components of the
        // same name does not let the inner instance wipe the outer's slots.
        $this->componentSlots[count($this->componentStack) - 1] = [];
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

        // The popped component lived at depth count() (its former last index).
        $depth = count($this->componentStack);
        $slots = $this->componentSlots[$depth] ?? [];
        unset($this->componentSlots[$depth]);

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

        $componentDepth = count($this->componentStack) - 1;

        if (!isset($this->componentSlots[$componentDepth])) {
            $this->componentSlots[$componentDepth] = [];
        }

        $this->componentSlots[$componentDepth][$slot['name']] = $content;
    }

    /**
     * Register a @once block by id and report whether it should render now.
     *
     * The registry lives on the shared $__env, so a @once block inside a partial
     * that is @included N times renders only on the first encounter — the
     * once-per-request contract — instead of once per isolated template execution.
     */
    public function renderOnce(string $id): bool
    {
        if (array_key_exists($id, $this->onceIds)) {
            return false;
        }

        $this->onceIds[$id] = true;

        return true;
    }

    /**
     * Reset state between template renders.
     */
    public function reset(): void
    {
        $this->sections = [];
        $this->sectionStack = [];
        $this->stacks = [];
        $this->pushStack = [];
        $this->parent = null;
        $this->componentStack = [];
        $this->slotStack = [];
        $this->componentSlots = [];
        $this->onceIds = [];
    }
}
