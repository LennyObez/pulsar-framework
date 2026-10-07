<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use ReflectionClass;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_unique;
use function class_exists;
use function get_defined_functions;
use function interface_exists;
use function is_object;
use function sort;
use function str_starts_with;

/**
 * Provides autocompletion suggestions for the REPL.
 *
 * Resolves container bindings, class methods, scope variables,
 * PHP built-in functions, and Pulsar helper names for tab-completion.
 * @api
 */
#[Api(since: '1.0.0')]
final class AutoCompleter
{
    /** @var array<string, mixed> */
    private array $scopeVariables = [];

    /** @var list<string> */
    private array $helperNames = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {}

    /**
     * Update the in-scope variables for completion.
     *
     * @param array<string, mixed> $variables Variable name => value pairs
     */
    public function updateScope(array $variables): void
    {
        $this->scopeVariables = $variables;
    }

    /**
     * Register helper function names for completion.
     *
     * @param list<string> $names
     */
    public function registerHelpers(array $names): void
    {
        $this->helperNames = $names;
    }

    /**
     * Get completion suggestions for a partial input.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function complete(string $input): array
    {
        $input = ltrim($input);

        if ($input === '') {
            return [];
        }

        // Method completion: $var->met... → method suggestions (before variable check)
        if (str_contains($input, '->')) {
            return $this->completeMethod($input);
        }

        // Static method completion: ClassName::met...
        if (str_contains($input, '::')) {
            return $this->completeStaticMethod($input);
        }

        // Variable completion: $par... → $parameters
        if (str_starts_with($input, '$')) {
            return $this->completeVariable($input);
        }

        // General completion: function names, class names, helpers
        return $this->completeGeneral($input);
    }

    /**
     * Get all container binding IDs for completion.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function getContainerBindings(): array
    {
        if ($this->container === null) {
            return [];
        }

        $bindings = array_keys($this->container->getBindings());
        $instances = array_keys($this->container->getInstances());

        /** @var list<string> $merged */
        $merged = array_unique(array_merge($bindings, $instances));
        sort($merged);

        return $merged;
    }

    /**
     * Get method names for a class.
     *
     * @param class-string $className
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function getClassMethods(string $className): array
    {
        if (!class_exists($className) && !interface_exists($className)) {
            return [];
        }

        $reflection = new ReflectionClass($className);

        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic()) {
                $methods[] = $method->getName();
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * Suggest variable names matching a prefix.
     *
     * @return list<string>
     */
    private function completeVariable(string $prefix): array
    {
        $varPrefix = ltrim($prefix, '$');
        $suggestions = [];

        foreach (array_keys($this->scopeVariables) as $name) {
            if ($varPrefix === '' || str_starts_with($name, $varPrefix)) {
                $suggestions[] = '$' . $name;
            }
        }

        sort($suggestions);

        return $suggestions;
    }

    /**
     * Suggest methods on a variable's class.
     *
     * @return list<string>
     */
    private function completeMethod(string $input): array
    {
        $parts = explode('->', $input, 2);
        $varName = ltrim($parts[0], '$');
        $methodPrefix = $parts[1] ?? '';

        if (!isset($this->scopeVariables[$varName])) {
            return [];
        }

        $value = $this->scopeVariables[$varName];

        if (!is_object($value)) {
            return [];
        }

        $methods = $this->getClassMethods($value::class);

        if ($methodPrefix === '') {
            return $methods;
        }

        return array_values(array_filter(
            $methods,
            static fn(string $m): bool => str_starts_with($m, $methodPrefix),
        ));
    }

    /**
     * Suggest static methods on a class.
     *
     * @return list<string>
     */
    private function completeStaticMethod(string $input): array
    {
        $parts = explode('::', $input, 2);
        $className = $parts[0];
        $methodPrefix = $parts[1] ?? '';

        if (!class_exists($className) && !interface_exists($className)) {
            return [];
        }

        /** @var class-string $className */
        $reflection = new ReflectionClass($className);
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic() && $method->isStatic()) {
                $name = $method->getName();

                if ($methodPrefix === '' || str_starts_with($name, $methodPrefix)) {
                    $methods[] = $className . '::' . $name;
                }
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * Suggest function names, helpers, and container bindings.
     *
     * @return list<string>
     */
    private function completeGeneral(string $prefix): array
    {
        $suggestions = [];

        // PHP built-in functions
        $builtins = get_defined_functions();

        foreach ($builtins['internal'] as $fn) {
            if (str_starts_with($fn, $prefix)) {
                $suggestions[] = $fn;
            }
        }

        // Registered helpers
        foreach ($this->helperNames as $helper) {
            if (str_starts_with($helper, $prefix)) {
                $suggestions[] = $helper;
            }
        }

        // Container bindings (class names)
        foreach ($this->getContainerBindings() as $binding) {
            if (str_starts_with($binding, $prefix)) {
                $suggestions[] = $binding;
            }
        }

        /** @var list<string> $unique */
        $unique = array_unique($suggestions);
        sort($unique);

        return $unique;
    }
}
