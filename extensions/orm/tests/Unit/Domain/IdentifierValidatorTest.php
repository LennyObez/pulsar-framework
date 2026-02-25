<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\IdentifierValidator;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

final class IdentifierValidatorTest extends TestCase
{
    #[Test]
    public function validateAcceptsValidIdentifiers(): void
    {
        IdentifierValidator::validate('users');
        IdentifierValidator::validate('_private');
        IdentifierValidator::validate('CamelCase');
        IdentifierValidator::validate('table_123');

        $this->addToAssertionCount(4);
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function validateRejectsInvalidIdentifiers(string $identifier): void
    {
        $this->expectException(QueryBuilderException::class);

        IdentifierValidator::validate($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'starts with number' => ['1users'];
        yield 'contains hyphen' => ['user-name'];
        yield 'contains space' => ['user name'];
        yield 'contains dot' => ['user.name'];
        yield 'empty string' => [''];
        yield 'special chars' => ['us@rs'];
    }

    #[Test]
    public function validateQualifiedAcceptsDottedIdentifier(): void
    {
        IdentifierValidator::validateQualified('t0.column');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateQualifiedAcceptsSimpleIdentifier(): void
    {
        IdentifierValidator::validateQualified('column');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateQualifiedRejectsInvalidAlias(): void
    {
        $this->expectException(QueryBuilderException::class);

        IdentifierValidator::validateQualified('1bad.column');
    }

    #[Test]
    public function validateQualifiedRejectsInvalidColumn(): void
    {
        $this->expectException(QueryBuilderException::class);

        IdentifierValidator::validateQualified('alias.bad-col');
    }

    #[Test]
    public function isValidReturnsBooleans(): void
    {
        self::assertTrue(IdentifierValidator::isValid('users'));
        self::assertTrue(IdentifierValidator::isValid('_col'));
        self::assertFalse(IdentifierValidator::isValid('1bad'));
        self::assertFalse(IdentifierValidator::isValid(''));
        self::assertFalse(IdentifierValidator::isValid('a-b'));
    }
}
