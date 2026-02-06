<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use function count;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Api\Internal;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumUnitCase;

use function sprintf;

use UnitEnum;

/**
 * Helpers for API compatibility tests.
 */
trait ApiAssertionsTrait
{
    /**
     * Assert that a class/interface/enum has the #[Api] attribute.
     *
     * @param class-string $class
     */
    private static function assertHasApiAttribute(string $class): void
    {
        $ref = new ReflectionClass($class);
        $attrs = $ref->getAttributes(Api::class);
        self::assertNotEmpty(
            $attrs,
            sprintf('Class %s must have #[Api] attribute', $class),
        );
    }

    /**
     * Assert that a class/interface/enum has the #[Internal] attribute.
     *
     * @param class-string $class
     */
    private static function assertHasInternalAttribute(string $class): void
    {
        $ref = new ReflectionClass($class);
        $attrs = $ref->getAttributes(Internal::class);
        self::assertNotEmpty(
            $attrs,
            sprintf('Class %s must have #[Internal] attribute', $class),
        );
    }

    /**
     * Assert a method exists with the expected parameter types and return type.
     *
     * @param class-string $class
     * @param string $method
     * @param list<string> $paramTypes Expected parameter type names (in order)
     * @param string $returnType Expected return type name
     */
    private static function assertMethodSignature(
        string $class,
        string $method,
        array $paramTypes,
        string $returnType,
    ): void {
        $ref = new ReflectionClass($class);
        self::assertTrue(
            $ref->hasMethod($method),
            sprintf('Class %s must have method %s()', $class, $method),
        );

        $methodRef = $ref->getMethod($method);
        $params = $methodRef->getParameters();

        self::assertCount(
            count($paramTypes),
            $params,
            sprintf('%s::%s() must have %d parameters', $class, $method, count($paramTypes)),
        );

        foreach ($paramTypes as $i => $expectedType) {
            $paramType = $params[$i]->getType();
            self::assertNotNull(
                $paramType,
                sprintf('%s::%s() parameter #%d must have a type', $class, $method, $i),
            );
            self::assertSame(
                $expectedType,
                (string) $paramType,
                sprintf('%s::%s() parameter #%d must be %s', $class, $method, $i, $expectedType),
            );
        }

        $returnTypeRef = $methodRef->getReturnType();
        self::assertNotNull(
            $returnTypeRef,
            sprintf('%s::%s() must have a return type', $class, $method),
        );
        self::assertSame(
            $returnType,
            (string) $returnTypeRef,
            sprintf('%s::%s() return type must be %s', $class, $method, $returnType),
        );
    }

    /**
     * Assert that an enum has the expected cases.
     *
     * @param class-string<UnitEnum> $enum
     * @param list<string> $expectedCases
     */
    private static function assertEnumCases(string $enum, array $expectedCases): void
    {
        $ref = new ReflectionEnum($enum);
        $actualCases = array_map(
            static fn(ReflectionEnumUnitCase $case): string => $case->getName(),
            $ref->getCases(),
        );

        sort($expectedCases);
        sort($actualCases);

        self::assertSame(
            $expectedCases,
            $actualCases,
            sprintf('Enum %s must have exactly these cases: %s', $enum, implode(', ', $expectedCases)),
        );
    }

    /**
     * Assert that a class is declared readonly.
     *
     * @param class-string $class
     */
    private static function assertClassIsReadonly(string $class): void
    {
        $ref = new ReflectionClass($class);
        self::assertTrue(
            $ref->isReadOnly(),
            sprintf('Class %s must be readonly', $class),
        );
    }

    /**
     * Assert that a static factory method exists on a class.
     *
     * @param class-string $class
     * @param string $method
     */
    private static function assertStaticFactoryExists(string $class, string $method): void
    {
        $ref = new ReflectionClass($class);
        self::assertTrue(
            $ref->hasMethod($method),
            sprintf('Class %s must have static factory method %s()', $class, $method),
        );

        $methodRef = $ref->getMethod($method);
        self::assertTrue(
            $methodRef->isStatic(),
            sprintf('%s::%s() must be static', $class, $method),
        );
    }

    /**
     * Assert that a class is final.
     *
     * @param class-string $class
     */
    private static function assertClassIsFinal(string $class): void
    {
        $ref = new ReflectionClass($class);
        self::assertTrue(
            $ref->isFinal(),
            sprintf('Class %s must be final', $class),
        );
    }

    /**
     * Assert that a class implements a given interface.
     *
     * @param class-string $class
     * @param class-string $interface
     */
    private static function assertClassImplements(string $class, string $interface): void
    {
        $ref = new ReflectionClass($class);
        self::assertTrue(
            $ref->implementsInterface($interface),
            sprintf('Class %s must implement %s', $class, $interface),
        );
    }

    /**
     * Assert that a method has the #[Override] attribute.
     *
     * @param class-string $class
     */
    private static function assertMethodHasOverrideAttribute(string $class, string $method): void
    {
        $ref = new ReflectionClass($class);
        self::assertTrue(
            $ref->hasMethod($method),
            sprintf('Class %s must have method %s()', $class, $method),
        );

        $methodRef = $ref->getMethod($method);
        $attrs = $methodRef->getAttributes(Override::class);
        self::assertNotEmpty(
            $attrs,
            sprintf('%s::%s() must have #[Override] attribute', $class, $method),
        );
    }

    /**
     * Assert that a method has the #[NoDiscard] attribute.
     *
     * @param class-string $class
     */
    private static function assertMethodHasNoDiscardAttribute(string $class, string $method): void
    {
        $ref = new ReflectionClass($class);
        self::assertTrue(
            $ref->hasMethod($method),
            sprintf('Class %s must have method %s()', $class, $method),
        );

        $methodRef = $ref->getMethod($method);
        $attrs = $methodRef->getAttributes(NoDiscard::class);
        self::assertNotEmpty(
            $attrs,
            sprintf('%s::%s() must have #[NoDiscard] attribute', $class, $method),
        );
    }
}
