<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\AuthDirective;

use function str_contains;

#[CoversClass(AuthDirective::class)]
final class AuthDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsAuth(): void
    {
        $directive = new AuthDirective();

        self::assertSame('auth', $directive->name());
    }

    #[Test]
    public function nameReturnsCustomDirectiveName(): void
    {
        $directive = new AuthDirective('authenticated');

        self::assertSame('authenticated', $directive->name());
    }

    #[Test]
    public function compileWithoutGuardCallsAuthenticated(): void
    {
        $directive = new AuthDirective();

        $output = $directive->compile('');

        self::assertSame('<?php if ($__auth->authenticated()): ?>', $output);
    }

    #[Test]
    public function compileIgnoresGuardArgument(): void
    {
        // TemplateAuthHelper has no guard concept; the guard argument must be
        // dropped rather than emitted into an unsupported method call.
        $directive = new AuthDirective();

        $output = $directive->compile("'admin'");

        self::assertSame('<?php if ($__auth->authenticated()): ?>', $output);
    }

    #[Test]
    public function compiledOutputDoesNotCallNonExistentCheckMethod(): void
    {
        // Regression: the directive used to emit $__auth->check(...), a method
        // that does not exist on TemplateAuthHelper, causing a fatal error at
        // template runtime. The compiled output must call authenticated()
        // (a real TemplateAuthHelper method) and never check().
        $output = new AuthDirective()->compile('');

        self::assertTrue(str_contains($output, 'authenticated()'));
        self::assertFalse(str_contains($output, '->check('));
    }
}
