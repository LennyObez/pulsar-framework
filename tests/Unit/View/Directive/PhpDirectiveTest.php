<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\PhpDirective;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

#[CoversClass(PhpDirective::class)]
final class PhpDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsPhp(): void
    {
        $config = new ViewConfig(templatePaths: [], cachePath: '/tmp/cache', phpDirectiveAllowed: true);

        self::assertSame('php', new PhpDirective($config)->name());
    }

    #[Test]
    public function compileReturnsPhpTagWhenAllowed(): void
    {
        $config = new ViewConfig(templatePaths: [], cachePath: '/tmp/cache', phpDirectiveAllowed: true);

        $output = new PhpDirective($config)->compile('');

        self::assertSame('<?php', $output);
    }

    #[Test]
    public function compileThrowsWhenDisabled(): void
    {
        $config = new ViewConfig(templatePaths: [], cachePath: '/tmp/cache', phpDirectiveAllowed: false);
        $directive = new PhpDirective($config);

        $this->expectException(ViewException::class);

        $directive->compile('');
    }
}
