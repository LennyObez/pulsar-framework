<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\YieldDirective;

#[CoversClass(YieldDirective::class)]
final class YieldDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsYield(): void
    {
        self::assertSame('yield', new YieldDirective()->name());
    }

    #[Test]
    public function compileProducesYieldSectionCall(): void
    {
        $output = new YieldDirective()->compile("'content', 'Default text'");

        self::assertSame("<?php echo \$__env->yieldSection('content', 'Default text'); ?>", $output);
    }
}
