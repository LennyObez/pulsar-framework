<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\SectionDirective;

#[CoversClass(SectionDirective::class)]
final class SectionDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsSection(): void
    {
        self::assertSame('section', new SectionDirective()->name());
    }

    #[Test]
    public function compileProducesStartSectionCall(): void
    {
        $output = new SectionDirective()->compile("'sidebar'");

        self::assertSame("<?php \$__env->startSection('sidebar'); ?>", $output);
    }
}
