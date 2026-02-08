<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ScaffoldTrait;

#[CoversClass(ScaffoldTrait::class)]
final class ScaffoldTraitTest extends TestCase
{
    use ScaffoldTrait;

    #[Test]
    public function to_pascal_case_converts_kebab_case(): void
    {
        self::assertSame('UserProfile', $this->toPascalCase('user-profile'));
    }

    #[Test]
    public function to_pascal_case_converts_snake_case(): void
    {
        self::assertSame('UserProfile', $this->toPascalCase('user_profile'));
    }

    #[Test]
    public function to_pascal_case_preserves_pascal_case(): void
    {
        self::assertSame('UserProfile', $this->toPascalCase('UserProfile'));
    }

    #[Test]
    public function to_pascal_case_handles_single_word(): void
    {
        self::assertSame('User', $this->toPascalCase('user'));
    }

    #[Test]
    public function to_kebab_case_converts_pascal_case(): void
    {
        self::assertSame('user-profile', $this->toKebabCase('UserProfile'));
    }

    #[Test]
    public function to_kebab_case_converts_snake_case(): void
    {
        self::assertSame('user-profile', $this->toKebabCase('user_profile'));
    }

    #[Test]
    public function to_kebab_case_handles_single_word(): void
    {
        self::assertSame('user', $this->toKebabCase('user'));
    }

    #[Test]
    public function to_camel_case_converts_kebab_case(): void
    {
        self::assertSame('userProfile', $this->toCamelCase('user-profile'));
    }

    #[Test]
    public function to_camel_case_converts_pascal_case(): void
    {
        self::assertSame('userProfile', $this->toCamelCase('UserProfile'));
    }

    #[Test]
    public function to_snake_case_converts_pascal_case(): void
    {
        self::assertSame('user_profile', $this->toSnakeCase('UserProfile'));
    }

    #[Test]
    public function resolve_base_path_returns_absolute_path(): void
    {
        $result = $this->resolveBasePath('app/Modules', 'app/Modules');

        self::assertIsString($result);
        self::assertStringEndsWith('app' . DIRECTORY_SEPARATOR . 'Modules', $result);
    }

    #[Test]
    public function resolve_base_path_uses_default_when_empty(): void
    {
        $result = $this->resolveBasePath('', 'app/Modules');

        self::assertIsString($result);
        self::assertStringEndsWith('app' . DIRECTORY_SEPARATOR . 'Modules', $result);
    }
}
