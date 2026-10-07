<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Controller;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Controller\HandlerParameter;
use stdClass;

/**
 * {@see HandlerParameter::accepts()} — the one place a resolver asks whether a
 * declared parameter would take an object.
 *
 * Every answer here is a claim decision somewhere else: a false where PHP would
 * have accepted the value means the authorized bound model is dropped and the
 * kernel fills the slot from the route parameters instead, so the handler
 * receives the raw URL string on a parameter that had declared an entity
 * acceptable — silently, with a 200.
 */
#[CoversClass(HandlerParameter::class)]
final class HandlerParameterTest extends TestCase
{
    #[Test]
    public function aClassTypedParameterAcceptsAnInstanceOfThatClass(): void
    {
        self::assertTrue($this->parameter('object', stdClass::class, false, [[stdClass::class]])->accepts(new stdClass()));
    }

    #[Test]
    public function aClassTypedParameterAcceptsASubclass(): void
    {
        $parameter = $this->parameter('entity', ParameterBase::class, false, [[ParameterBase::class]]);

        self::assertTrue($parameter->accepts(new ParameterChild()));
    }

    #[Test]
    public function aClassTypedParameterRefusesAnUnrelatedObject(): void
    {
        self::assertFalse($this->parameter('entity', ParameterBase::class, false, [[ParameterBase::class]])->accepts(new stdClass()));
    }

    /**
     * `object` is a builtin type name and accepts every object there is. Reading
     * "builtin" as "no object matches" is what made a handler declaring
     * `show(object $post)` look, to the bound-model resolver, exactly like one
     * that accepts nothing.
     */
    #[Test]
    public function aParameterTypedObjectAcceptsEveryObject(): void
    {
        $parameter = $this->parameter('any', 'object', true, [['object']]);

        self::assertTrue($parameter->accepts(new stdClass()));
        self::assertTrue($parameter->accepts(new ParameterChild()));
    }

    /**
     * The other builtin name that accepts every object. `mixed` accepts every
     * VALUE, so an object is never the reason to decline it.
     */
    #[Test]
    public function aParameterTypedMixedAcceptsEveryObject(): void
    {
        $parameter = $this->parameter('any', 'mixed', true, [['mixed']]);

        self::assertTrue($parameter->accepts(new stdClass()));
        self::assertTrue($parameter->accepts(new ParameterChild()));
    }

    /**
     * `iterable` is `array|Traversable`, so whether it accepts an object is a
     * property of the object rather than of the name.
     */
    #[Test]
    public function aParameterTypedIterableAcceptsOnlyATraversableObject(): void
    {
        $parameter = $this->parameter('items', 'iterable', true, [['iterable']]);

        self::assertTrue($parameter->accepts(new ArrayIterator([])));
        self::assertFalse($parameter->accepts(new stdClass()));
    }

    /**
     * Same shape for `callable`: an object with `__invoke` is callable and PHP
     * would bind it, so declining it would drop a value the handler declared it
     * could take.
     */
    #[Test]
    public function aParameterTypedCallableAcceptsOnlyAnInvokableObject(): void
    {
        $parameter = $this->parameter('handler', 'callable', true, [['callable']]);

        self::assertTrue($parameter->accepts(new ParameterInvokable()));
        self::assertFalse($parameter->accepts(new stdClass()));
    }

    #[Test]
    public function theScalarBuiltinsAcceptNoObject(): void
    {
        foreach (['string', 'int', 'float', 'bool', 'array'] as $name) {
            self::assertFalse(
                $this->parameter('value', $name, true, [[$name]])->accepts(new stdClass()),
                $name . ' must accept no object.',
            );
        }
    }

    #[Test]
    public function anUntypedParameterAcceptsNothing(): void
    {
        self::assertFalse(new HandlerParameter('value', null, false, false, null)->accepts(new stdClass()));
    }

    /**
     * A union accepts the object when ANY alternative does — including when the
     * alternative that matches is `object`, which no `instanceof` can see.
     */
    #[Test]
    public function aUnionAcceptsTheObjectWhenAnyAlternativeDoes(): void
    {
        $entityOrString = $this->parameter('user', null, false, [[ParameterBase::class], ['string']]);
        self::assertTrue($entityOrString->accepts(new ParameterChild()));

        $unrelatedOrString = $this->parameter('user', null, false, [[ParameterInvokable::class], ['string']]);
        self::assertFalse($unrelatedOrString->accepts(new ParameterChild()));
    }

    /**
     * An intersection accepts the object only when EVERY name in it matches.
     * Collapsing the two would turn "all of these" into "any of these" and claim
     * a parameter the handler would refuse with a TypeError.
     */
    #[Test]
    public function anIntersectionRequiresEveryNameToMatch(): void
    {
        $parameter = $this->parameter('user', null, false, [[ParameterBase::class, ParameterMarker::class]]);

        self::assertTrue($parameter->accepts(new ParameterMarkedChild()));
        self::assertFalse($parameter->accepts(new ParameterChild()));
    }

    /**
     * An instance built from `$type` alone — the four-field construction that
     * predates the DNF field — keeps answering from the single name.
     */
    #[Test]
    public function aParameterBuiltWithoutAlternativesFallsBackToTheSingleType(): void
    {
        self::assertTrue(new HandlerParameter('user', ParameterBase::class, false, false, null)->accepts(new ParameterChild()));
        self::assertTrue(new HandlerParameter('any', 'object', true, false, null)->accepts(new stdClass()));
        self::assertFalse(new HandlerParameter('raw', 'string', true, false, null)->accepts(new stdClass()));
    }

    /**
     * @param list<list<string>> $alternatives
     */
    private function parameter(string $name, ?string $type, bool $builtin, array $alternatives): HandlerParameter
    {
        return new HandlerParameter($name, $type, $builtin, false, null, $alternatives);
    }
}

class ParameterBase {}

interface ParameterMarker {}

final class ParameterChild extends ParameterBase {}

final class ParameterMarkedChild extends ParameterBase implements ParameterMarker {}

final class ParameterInvokable
{
    public function __invoke(): void {}
}
