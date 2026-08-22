<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\IdentifierNormalizer;

#[CoversClass(IdentifierNormalizer::class)]
final class IdentifierNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function classNameProvider(): iterable
    {
        yield 'simple snake_case' => ['users', 'Users'];
        yield 'plural snake_case' => ['user_profiles', 'UserProfiles'];
        yield 'with hyphens' => ['user-roles', 'UserRoles'];
        yield 'already PascalCase' => ['UserRole', 'UserRole'];
        yield 'camelCase' => ['userRole', 'UserRole'];
        yield 'single word' => ['post', 'Post'];
        yield 'with numbers' => ['oauth2_tokens', 'Oauth2Tokens'];
        yield 'consecutive underscores' => ['user__role', 'UserRole'];
        yield 'leading underscore' => ['_internal_table', 'InternalTable'];
    }

    #[Test]
    #[DataProvider('classNameProvider')]
    public function toClassNameConvertsCorrectly(string $input, string $expected): void
    {
        self::assertSame($expected, IdentifierNormalizer::toClassName($input));
    }

    #[Test]
    public function toClassNameHandlesPhpReservedWord(): void
    {
        self::assertSame('ClassEntity', IdentifierNormalizer::toClassName('class'));
        self::assertSame('InterfaceEntity', IdentifierNormalizer::toClassName('interface'));
        self::assertSame('MatchEntity', IdentifierNormalizer::toClassName('match'));
    }

    #[Test]
    public function toClassNameHandlesSqlReservedWord(): void
    {
        self::assertSame('SelectEntity', IdentifierNormalizer::toClassName('select'));
        self::assertSame('TableEntity', IdentifierNormalizer::toClassName('table'));
    }

    #[Test]
    public function toClassNameHandlesEmptyString(): void
    {
        self::assertSame('Entity', IdentifierNormalizer::toClassName(''));
    }

    #[Test]
    public function toClassNameHandlesSpecialCharacters(): void
    {
        // Special characters are stripped but don't act as word separators
        self::assertSame('Usertable', IdentifierNormalizer::toClassName('user$table'));
        self::assertSame('Usertable', IdentifierNormalizer::toClassName('user@table'));

        // Underscores and hyphens are proper separators
        self::assertSame('UserTable', IdentifierNormalizer::toClassName('user_table'));
        self::assertSame('UserTable', IdentifierNormalizer::toClassName('user-table'));
    }

    #[Test]
    public function toClassNameHandlesNumericPrefix(): void
    {
        $result = IdentifierNormalizer::toClassName('123table');

        // Must start with a letter
        self::assertMatchesRegularExpression('/^[A-Z]/', $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function propertyNameProvider(): iterable
    {
        yield 'simple snake_case' => ['user_name', 'userName'];
        yield 'with hyphens' => ['first-name', 'firstName'];
        yield 'already camelCase' => ['firstName', 'firstName'];
        yield 'single word' => ['name', 'name'];
        yield 'with numbers' => ['address_line_1', 'addressLine1'];
        yield 'uppercase' => ['STATUS', 'status'];
    }

    #[Test]
    #[DataProvider('propertyNameProvider')]
    public function toPropertyNameConvertsCorrectly(string $input, string $expected): void
    {
        self::assertSame($expected, IdentifierNormalizer::toPropertyName($input));
    }

    #[Test]
    public function toPropertyNameHandlesPhpReservedWord(): void
    {
        self::assertSame('classValue', IdentifierNormalizer::toPropertyName('class'));
        self::assertSame('matchValue', IdentifierNormalizer::toPropertyName('match'));
        self::assertSame('listValue', IdentifierNormalizer::toPropertyName('list'));
    }

    #[Test]
    public function toPropertyNameHandlesSqlReservedWord(): void
    {
        self::assertSame('selectValue', IdentifierNormalizer::toPropertyName('select'));
        self::assertSame('orderValue', IdentifierNormalizer::toPropertyName('order'));
    }

    #[Test]
    public function toPropertyNameHandlesEmptyString(): void
    {
        self::assertSame('field', IdentifierNormalizer::toPropertyName(''));
    }

    #[Test]
    public function toPropertyNameHandlesNumericPrefix(): void
    {
        $result = IdentifierNormalizer::toPropertyName('123field');

        // Must start with a letter or underscore
        self::assertMatchesRegularExpression('/^[a-z_]/', $result);
    }
}
