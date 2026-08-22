<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Marketplace\VersionConstraint;

#[CoversClass(VersionConstraint::class)]
final class VersionConstraintTest extends TestCase
{
    #[Test]
    public function exactVersionMatch(): void
    {
        $constraint = VersionConstraint::parse('1.2.3');

        self::assertTrue($constraint->isSatisfiedBy('1.2.3'));
        self::assertFalse($constraint->isSatisfiedBy('1.2.4'));
        self::assertFalse($constraint->isSatisfiedBy('1.2.2'));
    }

    #[Test]
    #[DataProvider('caretConstraintProvider')]
    public function caretConstraint(string $constraint, string $version, bool $expected): void
    {
        $parsed = VersionConstraint::parse($constraint);

        self::assertSame($expected, $parsed->isSatisfiedBy($version));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function caretConstraintProvider(): iterable
    {
        // ^1.2.3 → >=1.2.3 <2.0.0
        yield '^1.2.3 matches 1.2.3' => ['^1.2.3', '1.2.3', true];
        yield '^1.2.3 matches 1.9.9' => ['^1.2.3', '1.9.9', true];
        yield '^1.2.3 rejects 2.0.0' => ['^1.2.3', '2.0.0', false];
        yield '^1.2.3 rejects 1.2.2' => ['^1.2.3', '1.2.2', false];

        // ^0.2.3 → >=0.2.3 <0.3.0
        yield '^0.2.3 matches 0.2.3' => ['^0.2.3', '0.2.3', true];
        yield '^0.2.3 matches 0.2.9' => ['^0.2.3', '0.2.9', true];
        yield '^0.2.3 rejects 0.3.0' => ['^0.2.3', '0.3.0', false];

        // ^0.0.3 → >=0.0.3 <0.0.4
        yield '^0.0.3 matches 0.0.3' => ['^0.0.3', '0.0.3', true];
        yield '^0.0.3 rejects 0.0.4' => ['^0.0.3', '0.0.4', false];
    }

    #[Test]
    #[DataProvider('tildeConstraintProvider')]
    public function tildeConstraint(string $constraint, string $version, bool $expected): void
    {
        $parsed = VersionConstraint::parse($constraint);

        self::assertSame($expected, $parsed->isSatisfiedBy($version));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function tildeConstraintProvider(): iterable
    {
        // ~1.2.3 → >=1.2.3 <1.3.0
        yield '~1.2.3 matches 1.2.3' => ['~1.2.3', '1.2.3', true];
        yield '~1.2.3 matches 1.2.9' => ['~1.2.3', '1.2.9', true];
        yield '~1.2.3 rejects 1.3.0' => ['~1.2.3', '1.3.0', false];
        yield '~1.2.3 rejects 2.0.0' => ['~1.2.3', '2.0.0', false];
    }

    #[Test]
    #[DataProvider('wildcardConstraintProvider')]
    public function wildcardConstraint(string $constraint, string $version, bool $expected): void
    {
        $parsed = VersionConstraint::parse($constraint);

        self::assertSame($expected, $parsed->isSatisfiedBy($version));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function wildcardConstraintProvider(): iterable
    {
        // 1.2.* → >=1.2.0 <1.3.0
        yield '1.2.* matches 1.2.0' => ['1.2.*', '1.2.0', true];
        yield '1.2.* matches 1.2.99' => ['1.2.*', '1.2.99', true];
        yield '1.2.* rejects 1.3.0' => ['1.2.*', '1.3.0', false];
        yield '1.2.* rejects 1.1.0' => ['1.2.*', '1.1.0', false];

        // 1.* → >=1.0.0 <2.0.0
        yield '1.* matches 1.0.0' => ['1.*', '1.0.0', true];
        yield '1.* matches 1.99.99' => ['1.*', '1.99.99', true];
        yield '1.* rejects 2.0.0' => ['1.*', '2.0.0', false];
    }

    #[Test]
    public function parseTrimsWhitespace(): void
    {
        $constraint = VersionConstraint::parse('  ^1.0.0  ');

        self::assertTrue($constraint->isSatisfiedBy('1.5.0'));
    }
}
