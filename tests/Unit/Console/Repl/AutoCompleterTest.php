<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\AutoCompleter;
use Pulsar\Container\BindingType;
use Pulsar\Container\ContainerInterface;
use stdClass;

#[CoversClass(AutoCompleter::class)]
final class AutoCompleterTest extends TestCase
{
    #[Test]
    public function completeVariableReturnsMatchingScopeVars(): void
    {
        $completer = new AutoCompleter();
        $completer->updateScope([
            'container' => new stdClass(),
            'connection' => new stdClass(),
            'router' => new stdClass(),
        ]);

        $suggestions = $completer->complete('$con');

        self::assertContains('$container', $suggestions);
        self::assertContains('$connection', $suggestions);
        self::assertNotContains('$router', $suggestions);
    }

    #[Test]
    public function completeDollarSignAloneReturnsAllVars(): void
    {
        $completer = new AutoCompleter();
        $completer->updateScope(['a' => 1, 'b' => 2]);

        $suggestions = $completer->complete('$');

        self::assertCount(2, $suggestions);
        self::assertContains('$a', $suggestions);
        self::assertContains('$b', $suggestions);
    }

    #[Test]
    public function completeEmptyInputReturnsEmpty(): void
    {
        $completer = new AutoCompleter();

        self::assertSame([], $completer->complete(''));
    }

    #[Test]
    public function completeMethodReturnsMethods(): void
    {
        $obj = new class {
            public function getName(): string
            {
                return 'test';
            }

            public function getValue(): int
            {
                return 42;
            }
        };

        $completer = new AutoCompleter();
        $completer->updateScope(['obj' => $obj]);

        $suggestions = $completer->complete('$obj->get');

        self::assertContains('getName', $suggestions);
        self::assertContains('getValue', $suggestions);
    }

    #[Test]
    public function completeMethodOnUnknownVariableReturnsEmpty(): void
    {
        $completer = new AutoCompleter();
        $completer->updateScope([]);

        self::assertSame([], $completer->complete('$unknown->method'));
    }

    #[Test]
    public function completeMethodOnNonObjectReturnsEmpty(): void
    {
        $completer = new AutoCompleter();
        $completer->updateScope(['scalar' => 42]);

        self::assertSame([], $completer->complete('$scalar->method'));
    }

    #[Test]
    public function completeMethodWithEmptyPrefixReturnsAllPublicMethods(): void
    {
        $obj = new class {
            public function alpha(): void {}

            public function beta(): void {}
        };

        $completer = new AutoCompleter();
        $completer->updateScope(['obj' => $obj]);

        $suggestions = $completer->complete('$obj->');

        self::assertContains('alpha', $suggestions);
        self::assertContains('beta', $suggestions);
    }

    #[Test]
    public function completeStaticMethodReturnsSuggestions(): void
    {
        // Test with a class that definitely exists and has static methods
        $completer = new AutoCompleter();

        $suggestions = $completer->complete('PHPUnit\\Framework\\TestCase::');

        // TestCase has static methods like assertSame, assertEquals, etc.
        self::assertNotEmpty($suggestions);
    }

    #[Test]
    public function completeStaticMethodOnNonexistentClassReturnsEmpty(): void
    {
        $completer = new AutoCompleter();

        self::assertSame([], $completer->complete('NonExistentClass::'));
    }

    #[Test]
    public function completeGeneralMatchesHelpers(): void
    {
        $completer = new AutoCompleter();
        $completer->registerHelpers(['dump', 'doc', 'bench']);

        $suggestions = $completer->complete('du');

        self::assertContains('dump', $suggestions);
        self::assertNotContains('doc', $suggestions);
        self::assertNotContains('bench', $suggestions);
    }

    #[Test]
    public function completeGeneralMatchesBuiltinFunctions(): void
    {
        $completer = new AutoCompleter();

        $suggestions = $completer->complete('array_m');

        self::assertContains('array_map', $suggestions);
        self::assertContains('array_merge', $suggestions);
    }

    #[Test]
    public function getContainerBindingsWithoutContainerReturnsEmpty(): void
    {
        $completer = new AutoCompleter();

        self::assertSame([], $completer->getContainerBindings());
    }

    #[Test]
    public function getContainerBindingsReturnsSortedUnique(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([
            'Zebra\\Service' => ['concrete' => 'x', 'type' => BindingType::Singleton],
            'Alpha\\Service' => ['concrete' => 'y', 'type' => BindingType::Singleton],
        ]);
        $container->method('getInstances')->willReturn([
            'Alpha\\Service' => new stdClass(), // duplicate, should be deduped
            'Middle\\Service' => new stdClass(),
        ]);

        $completer = new AutoCompleter($container);
        $bindings = $completer->getContainerBindings();

        self::assertSame(['Alpha\\Service', 'Middle\\Service', 'Zebra\\Service'], $bindings);
    }

    #[Test]
    public function getClassMethodsForValidClassReturnsPublicMethods(): void
    {
        $completer = new AutoCompleter();
        $methods = $completer->getClassMethods(stdClass::class);

        // stdClass has no methods
        self::assertSame([], $methods);
    }

    #[Test]
    public function getClassMethodsForInvalidClassReturnsEmpty(): void
    {
        $completer = new AutoCompleter();

        $nonexistent = 'Nonexistent\\Class\\Name';
        $methods = $completer->getClassMethods($nonexistent); // @phpstan-ignore argument.type

        self::assertSame([], $methods);
    }

    #[Test]
    public function registerHelpersUpdatesCompletions(): void
    {
        $completer = new AutoCompleter();
        $completer->registerHelpers(['bench', 'profile']);

        $suggestions = $completer->complete('ben');

        self::assertContains('bench', $suggestions);
    }

    #[Test]
    public function completeGeneralMatchesContainerBindings(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([
            'App\\Service\\UserService' => ['concrete' => 'x', 'type' => BindingType::Singleton],
        ]);
        $container->method('getInstances')->willReturn([]);

        $completer = new AutoCompleter($container);

        $suggestions = $completer->complete('App\\Service\\U');

        self::assertContains('App\\Service\\UserService', $suggestions);
    }
}
