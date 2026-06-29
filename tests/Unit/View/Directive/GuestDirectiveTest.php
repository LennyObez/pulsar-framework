<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\GuestDirective;

use function str_contains;

#[CoversClass(GuestDirective::class)]
final class GuestDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsGuest(): void
    {
        self::assertSame('guest', new GuestDirective()->name());
    }

    #[Test]
    public function compileWithoutGuardCallsGuest(): void
    {
        $output = new GuestDirective()->compile('');

        self::assertSame('<?php if ($__auth->guest()): ?>', $output);
    }

    #[Test]
    public function compileIgnoresGuardArgument(): void
    {
        // TemplateAuthHelper has no guard concept; the guard argument must be
        // dropped rather than emitted into an unsupported method call.
        $output = new GuestDirective()->compile("'api'");

        self::assertSame('<?php if ($__auth->guest()): ?>', $output);
    }

    #[Test]
    public function compiledOutputDoesNotCallNonExistentCheckMethod(): void
    {
        // Regression: the directive used to emit !$__auth->check(...), a method
        // that does not exist on TemplateAuthHelper, causing a fatal error at
        // template runtime. The compiled output must call guest() (a real
        // TemplateAuthHelper method) and never check().
        $output = new GuestDirective()->compile('');

        self::assertTrue(str_contains($output, 'guest()'));
        self::assertFalse(str_contains($output, '->check('));
    }
}
