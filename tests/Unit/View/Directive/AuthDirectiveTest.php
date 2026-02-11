<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\AuthDirective;

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
    public function compileWithoutGuardUsesNull(): void
    {
        $directive = new AuthDirective();

        $output = $directive->compile('');

        self::assertSame('<?php if ($__auth->check(null)): ?>', $output);
    }

    #[Test]
    public function compileWithGuardPassesGuardName(): void
    {
        $directive = new AuthDirective();

        $output = $directive->compile("'admin'");

        self::assertSame("<?php if (\$__auth->check('admin')): ?>", $output);
    }
}
