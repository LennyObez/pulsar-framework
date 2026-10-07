<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\ExtendsDirective;

#[CoversClass(ExtendsDirective::class)]
final class ExtendsDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsExtends(): void
    {
        self::assertSame('extends', new ExtendsDirective()->name());
    }

    #[Test]
    public function compileProducesSetParentCall(): void
    {
        $output = new ExtendsDirective()->compile("'layouts.main'");

        self::assertSame("<?php \$__env->setParent('layouts.main'); ?>", $output);
    }
}
