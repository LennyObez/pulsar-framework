<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\TypedDirective;

#[CoversClass(TypedDirective::class)]
final class TypedDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_typed(): void
    {
        $directive = new TypedDirective();

        self::assertSame('typed', $directive->name());
    }

    #[Test]
    public function compile_generates_type_check_loop(): void
    {
        $directive = new TypedDirective();

        $output = $directive->compile("['user' => User::class, 'count' => 'int']");

        self::assertStringContainsString('foreach', $output);
        self::assertStringContainsString('$__typed_name', $output);
        self::assertStringContainsString('$__typed_type', $output);
    }

    #[Test]
    public function compile_checks_for_undefined_variables(): void
    {
        $directive = new TypedDirective();

        $output = $directive->compile("['x' => 'string']");

        self::assertStringContainsString('!isset($$__typed_name)', $output);
        self::assertStringContainsString('typedTemplateViolation', $output);
    }

    #[Test]
    public function compile_validates_primitive_types(): void
    {
        $directive = new TypedDirective();

        $output = $directive->compile("['x' => 'int']");

        self::assertStringContainsString("'int', 'integer'", $output);
        self::assertStringContainsString("'string'", $output);
        self::assertStringContainsString("'bool', 'boolean'", $output);
        self::assertStringContainsString("'array'", $output);
        self::assertStringContainsString("'callable'", $output);
        self::assertStringContainsString("'iterable'", $output);
        self::assertStringContainsString("'mixed'", $output);
    }

    #[Test]
    public function compile_falls_back_to_instanceof_for_class_types(): void
    {
        $directive = new TypedDirective();

        $output = $directive->compile("['obj' => SomeClass::class]");

        self::assertStringContainsString('instanceof', $output);
    }

    #[Test]
    public function compile_cleans_up_internal_variables(): void
    {
        $directive = new TypedDirective();

        $output = $directive->compile("['x' => 'int']");

        self::assertStringContainsString('unset($__typed_name, $__typed_type, $__typed_actual, $__typed_valid)', $output);
    }

    #[Test]
    public function compile_allows_null_type_without_undefined_error(): void
    {
        $directive = new TypedDirective();

        $output = $directive->compile("['optional' => 'null']");

        // null type should skip the undefined check
        self::assertStringContainsString("'null'", $output);
    }
}
