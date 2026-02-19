<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewConfig;

use function array_key_exists;
use function array_keys;

/**
 * Registry for template directives.
 *
 * Registers built-in and custom directives with the template compiler.
 * Enforces policy constraints such as the @php directive prohibition.
 */
#[Internal(reason: 'Registry wiring is an implementation detail')]
final class DirectiveRegistry
{
    /** @var array<string, DirectiveInterface> */
    private array $directives = [];

    public function __construct(
        private readonly ViewConfig $config,
    ) {}

    /**
     * Register a directive.
     */
    public function register(DirectiveInterface $directive): void
    {
        $this->directives[$directive->name()] = $directive;
    }

    /**
     * Check if a directive is registered.
     */
    #[NoDiscard]
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->directives);
    }

    /**
     * Get a registered directive.
     */
    #[NoDiscard]
    public function get(string $name): ?DirectiveInterface
    {
        return $this->directives[$name] ?? null;
    }

    /**
     * Get all registered directive names.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function names(): array
    {
        return array_keys($this->directives);
    }

    /**
     * Bind all registered directives to a template compiler.
     */
    public function bindTo(TemplateCompiler $compiler): void
    {
        foreach ($this->directives as $name => $directive) {
            $compiler->registerDirective($name, $directive->compile(...));
        }
    }

    /**
     * Register all built-in directives.
     */
    public function registerBuiltins(): void
    {
        // Control flow
        $this->register(new ControlFlowDirective('if', 'if'));
        $this->register(new ControlFlowDirective('elseif', 'elseif'));
        $this->register(new SimpleDirective('else', '<?php else: ?>'));
        $this->register(new SimpleDirective('endif', '<?php endif; ?>'));

        // Foreach
        $this->register(new ControlFlowDirective('foreach', 'foreach'));
        $this->register(new SimpleDirective('endforeach', '<?php endforeach; ?>'));

        // For
        $this->register(new ControlFlowDirective('for', 'for'));
        $this->register(new SimpleDirective('endfor', '<?php endfor; ?>'));

        // While
        $this->register(new ControlFlowDirective('while', 'while'));
        $this->register(new SimpleDirective('endwhile', '<?php endwhile; ?>'));

        // Switch
        $this->register(new ControlFlowDirective('switch', 'switch'));
        $this->register(new CaseDirective());
        $this->register(new SimpleDirective('default', '<?php default: ?>'));
        $this->register(new SimpleDirective('endswitch', '<?php endswitch; ?>'));

        // Template structure
        $this->register(new ExtendsDirective());
        $this->register(new SectionDirective());
        $this->register(new SimpleDirective('endsection', '<?php $__env->endSection(); ?>'));
        $this->register(new YieldDirective());
        $this->register(new IncludeDirective());

        // Components
        $this->register(new ComponentDirective());
        $this->register(new SimpleDirective('endcomponent', '<?php echo $__env->renderComponent(); ?>'));
        $this->register(new SlotDirective());
        $this->register(new SimpleDirective('endslot', '<?php $__env->endSlot(); ?>'));

        // Auth
        $this->register(new AuthDirective('auth'));
        $this->register(new SimpleDirective('endauth', '<?php endif; ?>'));
        $this->register(new GuestDirective());
        $this->register(new SimpleDirective('endguest', '<?php endif; ?>'));
        $this->register(new CanDirective());
        $this->register(new SimpleDirective('endcan', '<?php endif; ?>'));

        // Forms
        $this->register(new CsrfDirective());
        $this->register(new MethodDirective());

        // i18n
        $this->register(new I18nDirective());

        // PHP blocks (policy-controlled)
        $this->register(new PhpDirective($this->config));
        $this->register(new SimpleDirective('endphp', '?>'));
    }
}
