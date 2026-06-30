<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\TimeTrapDirective;

use function str_contains;

#[CoversClass(TimeTrapDirective::class)]
final class TimeTrapDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsTimetrap(): void
    {
        self::assertSame('timetrap', new TimeTrapDirective()->name());
    }

    #[Test]
    public function compileWithoutArgumentRendersWithEmptyFormId(): void
    {
        $output = new TimeTrapDirective()->compile('');

        self::assertStringStartsWith('<?php echo', $output);
        self::assertStringContainsString('TimeTrapRenderer::renderGlobal', $output);
        self::assertStringContainsString("renderGlobal('')", $output);
        // No inline script — the time-trap is pure server-rendered HTML.
        self::assertFalse(str_contains($output, '<script'));
    }

    #[Test]
    public function compilePassesAFormIdExpressionThrough(): void
    {
        $output = new TimeTrapDirective()->compile("'contact'");

        self::assertStringContainsString("renderGlobal('contact')", $output);
    }
}
