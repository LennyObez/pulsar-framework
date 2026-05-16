<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\EndForeachDirective;

#[CoversClass(EndForeachDirective::class)]
final class EndForeachDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsEndforeach(): void
    {
        self::assertSame('endforeach', new EndForeachDirective()->name());
    }

    #[Test]
    public function compileOutputRestoresParentLoop(): void
    {
        $compiled = new EndForeachDirective()->compile('');

        self::assertStringContainsString('endforeach;', $compiled);
        self::assertStringContainsString('$loop = $__loopParent', $compiled);
    }
}
