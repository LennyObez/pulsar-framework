<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Event\Attribute\StormOverride;
use Pulsar\Event\Exception\EventException;
use Pulsar\Event\Internal\EventMapCompiler;
use Pulsar\Event\Internal\ListenerProvider;
use stdClass;

#[CoversClass(EventMapCompiler::class)]
final class EventMapCompilerTest extends TestCase
{
    protected function setUp(): void
    {
        ListenerProvider::resetStaticCaches();
    }

    // ---- compile() basic behavior ----

    #[Test]
    public function compileEmptyProviderReturnsEmptyMap(): void
    {
        $provider = new ListenerProvider();
        $compiler = new EventMapCompiler();

        $map = $compiler->compile($provider);

        self::assertSame([], $map);
    }

    #[Test]
    public function compileProducesCorrectStructureForInvokableListener(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();
        $provider->addListener(stdClass::class, $listener, priority: 7, moduleId: 'billing');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertArrayHasKey(stdClass::class, $map);

        $entry = $map[stdClass::class];
        self::assertCount(1, $entry['listeners']);
        self::assertSame(EMCInvokableListener::class, $entry['listeners'][0]['class']);
        self::assertSame('__invoke', $entry['listeners'][0]['method']);
        self::assertSame(7, $entry['listeners'][0]['priority']);
        self::assertSame('billing', $entry['listeners'][0]['moduleId']);
        self::assertSame(['billing'], $entry['listenerModuleIds']);
        self::assertFalse($entry['requiresEnvelope']);
        self::assertNull($entry['stormOverride']);
    }

    #[Test]
    public function compileProducesCorrectStructureForArrayCallableListener(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCMethodListener();

        /** @var callable $callable */
        $callable = [$listener, 'onEvent'];
        $provider->addListener(stdClass::class, $callable, priority: 3, moduleId: 'shipping');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertSame(EMCMethodListener::class, $map[stdClass::class]['listeners'][0]['class']);
        self::assertSame('onEvent', $map[stdClass::class]['listeners'][0]['method']);
    }

    #[Test]
    public function compileWithMultipleListenersForSameEvent(): void
    {
        $provider = new ListenerProvider();
        $listener1 = new EMCInvokableListener();
        $listener2 = new EMCMethodListener();

        $provider->addListener(stdClass::class, $listener1, priority: 10, moduleId: 'mod-a');

        /** @var callable $callable */
        $callable = [$listener2, 'onEvent'];
        $provider->addListener(stdClass::class, $callable, priority: 5, moduleId: 'mod-b');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertCount(2, $map[stdClass::class]['listeners']);
        self::assertSame(['mod-a', 'mod-b'], $map[stdClass::class]['listenerModuleIds']);
    }

    #[Test]
    public function compileDeduplicatesModuleIds(): void
    {
        $provider = new ListenerProvider();
        $listener1 = new EMCInvokableListener();
        $listener2 = new EMCMethodListener();

        $provider->addListener(stdClass::class, $listener1, moduleId: 'shared');

        /** @var callable $callable */
        $callable = [$listener2, 'onEvent'];
        $provider->addListener(stdClass::class, $callable, moduleId: 'shared');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertSame(['shared'], $map[stdClass::class]['listenerModuleIds']);
    }

    #[Test]
    public function compileExcludesEmptyModuleIds(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();
        $provider->addListener(stdClass::class, $listener); // no moduleId

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertSame([], $map[stdClass::class]['listenerModuleIds']);
    }

    #[Test]
    public function compileIncludesRequiresEnvelopeFromProvider(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();
        $provider->addListener(EMCRequiresEnvelopeEvent::class, $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertTrue($map[EMCRequiresEnvelopeEvent::class]['requiresEnvelope']);
    }

    #[Test]
    public function compileIncludesStormOverrideFromProvider(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();
        $provider->addListener(EMCStormOverrideEvent::class, $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertSame(128, $map[EMCStormOverrideEvent::class]['stormOverride']);
    }

    #[Test]
    public function compileWithMultipleEventClasses(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        $provider->addListener(stdClass::class, $listener);
        $provider->addListener(EMCStormOverrideEvent::class, $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertCount(2, $map);
        self::assertArrayHasKey(stdClass::class, $map);
        self::assertArrayHasKey(EMCStormOverrideEvent::class, $map);
    }

    // ---- compile() with string callable (class name as listener) ----

    #[Test]
    public function compileHandlesStringCallable(): void
    {
        $provider = new ListenerProvider();
        // String callable: class name that has __invoke
        $provider->addListener(stdClass::class, 'strlen');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertSame('strlen', $map[stdClass::class]['listeners'][0]['class']);
        self::assertSame('__invoke', $map[stdClass::class]['listeners'][0]['method']);
    }

    // ---- compile() closure rejection ----

    #[Test]
    public function compileThrowsForClosureListener(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(stdClass::class, static function (): void {});

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/closures cannot be compiled/');

        $compiler->compile($provider);
    }

    // ---- validateEventClassName ----

    #[Test]
    public function compileAcceptsValidClassName(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();
        $provider->addListener(stdClass::class, $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertArrayHasKey(stdClass::class, $map);
    }

    #[Test]
    public function compileAcceptsClassNameWithUnderscores(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        /** @phpstan-ignore argument.type */
        $provider->addListener('App\\Event\\My_Event_Class', $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertArrayHasKey('App\\Event\\My_Event_Class', $map);
    }

    #[Test]
    public function compileAcceptsClassNameWithDigitsUnder9(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        /** @phpstan-ignore argument.type */
        $provider->addListener('App\\Event\\Event12345678', $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertArrayHasKey('App\\Event\\Event12345678', $map);
    }

    #[Test]
    public function compileAcceptsMaxLengthClassName(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        $name = str_repeat('A', 255);
        /** @phpstan-ignore argument.type */
        $provider->addListener($name, $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertArrayHasKey($name, $map);
    }

    #[Test]
    public function compileRejectsClassNameExceedingMaxLength(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        $name = str_repeat('A', 256);
        /** @phpstan-ignore argument.type */
        $provider->addListener($name, $listener);

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/maximum length/');

        $compiler->compile($provider);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disallowedCharacterProvider(): array
    {
        return [
            'hyphen' => ['App\\Event\\My-Event'],
            'space' => ['App\\Event\\My Event'],
            'at sign' => ['App\\Event\\user@example'],
            'dot' => ['App\\Event\\my.event'],
            'exclamation' => ['App\\Event\\Alert!'],
            'hash' => ['App\\Event\\Issue#42'],
            'parenthesis' => ['App\\Event\\Func()'],
        ];
    }

    #[Test]
    #[DataProvider('disallowedCharacterProvider')]
    public function compileRejectsClassNameWithDisallowedCharacters(string $className): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        /** @phpstan-ignore argument.type */
        $provider->addListener($className, $listener);

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/disallowed characters/');

        $compiler->compile($provider);
    }

    #[Test]
    public function compileRejectsClassNameWithUuidPattern(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        // UUID pattern: 8-4-4-4-12 hex digits separated by backslash
        /** @phpstan-ignore argument.type */
        $provider->addListener('App\\a1b2c3d4\\e5f6\\7890\\abcd\\ef1234567890', $listener);

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/UUID/');

        $compiler->compile($provider);
    }

    #[Test]
    public function compileRejectsClassNameWithUuidPatternUsingUnderscores(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        // UUID pattern with underscores as separators
        /** @phpstan-ignore argument.type */
        $provider->addListener('App\\a1b2c3d4_e5f6_7890_abcd_ef1234567890', $listener);

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/UUID/');

        $compiler->compile($provider);
    }

    #[Test]
    public function compileRejectsClassNameWithDigitRunExceeding8(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        // 9+ consecutive digits
        /** @phpstan-ignore argument.type */
        $provider->addListener('App\\Event\\Event123456789', $listener);

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/digit run/');

        $compiler->compile($provider);
    }

    #[Test]
    public function compileRejectsClassNameWithLongDigitRun(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();

        // 20 consecutive digits
        /** @phpstan-ignore argument.type */
        $provider->addListener('App\\Event\\Event' . str_repeat('0', 20), $listener);

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/digit run/');

        $compiler->compile($provider);
    }

    // ---- export() ----

    #[Test]
    public function exportProducesValidPhpCode(): void
    {
        $compiler = new EventMapCompiler();
        $map = [
            stdClass::class => [
                'listeners' => [
                    ['class' => EMCInvokableListener::class, 'method' => '__invoke', 'priority' => 0, 'moduleId' => 'billing'],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => ['billing'],
            ],
        ];

        $code = $compiler->export($map);

        self::assertStringStartsWith('<?php', $code);
        self::assertStringContainsString('declare(strict_types=1)', $code);
        self::assertStringContainsString('Auto-generated by Pulsar EventMapCompiler', $code);
        self::assertStringContainsString('return ', $code);
    }

    #[Test]
    public function exportContainsListenerClassAndMethod(): void
    {
        $compiler = new EventMapCompiler();
        $map = [
            stdClass::class => [
                'listeners' => [
                    ['class' => 'App\\Listener\\OrderListener', 'method' => 'onOrder', 'priority' => 5, 'moduleId' => ''],
                ],
                'requiresEnvelope' => false,
                'stormOverride' => null,
                'listenerModuleIds' => [],
            ],
        ];

        $code = $compiler->export($map);

        self::assertStringContainsString('App\\\\Listener\\\\OrderListener', $code);
        self::assertStringContainsString('onOrder', $code);
    }

    #[Test]
    public function exportEmptyMapProducesValidPhp(): void
    {
        $compiler = new EventMapCompiler();
        $code = $compiler->export([]);

        self::assertStringContainsString('<?php', $code);
        self::assertStringContainsString('return ', $code);
    }

    #[Test]
    public function exportPreservesEnvelopeAndStormValues(): void
    {
        $compiler = new EventMapCompiler();
        $map = [
            stdClass::class => [
                'listeners' => [],
                'requiresEnvelope' => true,
                'stormOverride' => 64,
                'listenerModuleIds' => ['mod-x'],
            ],
        ];

        $code = $compiler->export($map);

        // The exported PHP should contain 'true' for requiresEnvelope and 64 for stormOverride
        self::assertStringContainsString('true', $code);
        self::assertStringContainsString('64', $code);
        self::assertStringContainsString('mod-x', $code);
    }

    // ---- Full round-trip: compile + export ----

    #[Test]
    public function compileAndExportProducesConsistentOutput(): void
    {
        $provider = new ListenerProvider();
        $listener = new EMCInvokableListener();
        $provider->addListener(stdClass::class, $listener, priority: 5, moduleId: 'billing');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);
        $code = $compiler->export($map);

        // Must contain the listener class name (backslashes are escaped in var_export output)
        self::assertStringContainsString(str_replace('\\', '\\\\', EMCInvokableListener::class), $code);
        // Must contain billing module id
        self::assertStringContainsString('billing', $code);
        // Must be valid PHP
        self::assertStringContainsString('<?php', $code);
    }

    // ---- parseListenerCallable: array with static class string ----

    #[Test]
    public function compileHandlesArrayCallableWithStaticClassName(): void
    {
        $provider = new ListenerProvider();

        /** @var callable $callable */
        $callable = [EMCMethodListener::class, 'staticHandler'];
        $provider->addListener(stdClass::class, $callable);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertSame(EMCMethodListener::class, $map[stdClass::class]['listeners'][0]['class']);
        self::assertSame('staticHandler', $map[stdClass::class]['listeners'][0]['method']);
    }
}

// ---- Test helpers ----

/** @internal */
final class EMCInvokableListener
{
    public function __invoke(): void {}
}

/** @internal */
final class EMCMethodListener
{
    public function onEvent(object $event): void {}

    public static function staticHandler(object $event): void {}
}

/** @internal */
#[RequiresEnvelope]
final class EMCRequiresEnvelopeEvent {}

/** @internal */
#[StormOverride(maxDepth: 128)]
final class EMCStormOverrideEvent {}
