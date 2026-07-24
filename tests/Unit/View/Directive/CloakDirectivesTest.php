<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\CloakMailDirective;
use Pulsar\View\Directive\CloakTelDirective;

#[CoversClass(CloakMailDirective::class)]
#[CoversClass(CloakTelDirective::class)]
final class CloakDirectivesTest extends TestCase
{
    #[Test]
    public function cloakMailCompilesToTheRenderer(): void
    {
        $directive = new CloakMailDirective();

        self::assertSame('cloakmail', $directive->name());
        self::assertSame(
            "<?php echo \\Pulsar\\View\\ContactCloak::mail('john', 'example.com'); ?>",
            $directive->compile("'john', 'example.com'"),
        );
    }

    #[Test]
    public function cloakTelCompilesToTheRenderer(): void
    {
        $directive = new CloakTelDirective();

        self::assertSame('cloaktel', $directive->name());
        self::assertSame(
            "<?php echo \\Pulsar\\View\\ContactCloak::tel('32495733136'); ?>",
            $directive->compile("'32495733136'"),
        );
    }
}
