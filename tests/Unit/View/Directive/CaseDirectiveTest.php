<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\CaseDirective;

#[CoversClass(CaseDirective::class)]
final class CaseDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsCase(): void
    {
        self::assertSame('case', new CaseDirective()->name());
    }

    #[Test]
    public function compileProducesCaseStatement(): void
    {
        $output = new CaseDirective()->compile("'active'");

        self::assertSame("<?php case 'active': ?>", $output);
    }
}
