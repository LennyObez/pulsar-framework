<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

    #[Test]
    public function compileEmptyProvider(): void
    {
        $provider = new ListenerProvider();
        $compiler = new EventMapCompiler();

        $map = $compiler->compile($provider);

        self::assertSame([], $map);
    }

    #[Test]
    public function compileWithListeners(): void
    {
        $provider = new ListenerProvider();
        $listener = new CompilableTestListener();
        $provider->addListener(stdClass::class, $listener, priority: 5, moduleId: 'billing');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertArrayHasKey(stdClass::class, $map);
        self::assertCount(1, $map[stdClass::class]['listeners']);
        self::assertSame(5, $map[stdClass::class]['listeners'][0]['priority']);
        self::assertSame('billing', $map[stdClass::class]['listeners'][0]['moduleId']);
        self::assertSame(['billing'], $map[stdClass::class]['listenerModuleIds']);
        self::assertFalse($map[stdClass::class]['requiresEnvelope']);
        self::assertNull($map[stdClass::class]['stormOverride']);
    }

    #[Test]
    public function exportGeneratesValidPhp(): void
    {
        $provider = new ListenerProvider();
        $listener = new CompilableTestListener();
        $provider->addListener(stdClass::class, $listener, moduleId: 'billing');

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);
        $code = $compiler->export($map);

        self::assertStringContainsString('<?php', $code);
        self::assertStringContainsString('declare(strict_types=1)', $code);
        self::assertStringContainsString('return ', $code);
        self::assertStringContainsString('Auto-generated', $code);
    }

    #[Test]
    public function rejectsClassNameExceedingMaxLength(): void
    {
        $provider = new ListenerProvider();
        $longName = str_repeat('A', 256);
        $provider->addListener($longName, static function (): void {}); // @phpstan-ignore argument.type

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/maximum length/');

        $compiler->compile($provider);
    }

    #[Test]
    public function rejectsClassNameWithDisallowedCharacters(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener('App\\Event\\My-Event', static function (): void {}); // @phpstan-ignore argument.type

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/disallowed characters/');

        $compiler->compile($provider);
    }

    #[Test]
    public function rejectsClassNameWithDigitRunExceeding8(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener('App\\Event\\Event123456789', static function (): void {}); // @phpstan-ignore argument.type

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/digit run/');

        $compiler->compile($provider);
    }

    #[Test]
    public function acceptsValidClassName(): void
    {
        $provider = new ListenerProvider();
        $listener = new CompilableTestListener();
        $provider->addListener(stdClass::class, $listener);

        $compiler = new EventMapCompiler();
        $map = $compiler->compile($provider);

        self::assertArrayHasKey(stdClass::class, $map);
    }

    #[Test]
    public function rejectsClassNameWithUuidPattern(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener('App\\Event\\a1b2c3d4\\e5f6\\7890\\abcd\\ef1234567890', static function (): void {}); // @phpstan-ignore argument.type

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/UUID/');

        $compiler->compile($provider);
    }

    #[Test]
    public function compileThrowsOnClosureListener(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(stdClass::class, static function (): void {});

        $compiler = new EventMapCompiler();

        $this->expectException(EventException::class);
        $this->expectExceptionMessageMatches('/closures cannot be compiled/');

        $compiler->compile($provider);
    }
}

/**
 * @internal Test helper — invokable class listener for compiler tests
 */
final class CompilableTestListener
{
    public function __invoke(): void {}
}
