<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\EndFormDirective;

#[CoversClass(EndFormDirective::class)]
final class EndFormDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsEndform(): void
    {
        $directive = new EndFormDirective();

        self::assertSame('endform', $directive->name());
    }

    #[Test]
    public function compileClosesFormTag(): void
    {
        $directive = new EndFormDirective();

        $result = $directive->compile('');

        self::assertStringContainsString('</form>', $result);
    }

    #[Test]
    public function compileCleansUpInternalVariables(): void
    {
        $directive = new EndFormDirective();

        $result = $directive->compile('');

        self::assertStringContainsString('unset(', $result);
        self::assertStringContainsString('$__form_dto', $result);
        self::assertStringContainsString('$__form_action', $result);
    }
}
