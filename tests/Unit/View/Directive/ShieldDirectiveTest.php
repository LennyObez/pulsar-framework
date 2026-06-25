<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\ShieldDirective;

#[CoversClass(ShieldDirective::class)]
final class ShieldDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsShield(): void
    {
        self::assertSame('shield', new ShieldDirective()->name());
    }

    #[Test]
    public function compileRendersManagedChallengeWithCspNonce(): void
    {
        $output = new ShieldDirective()->compile('');

        self::assertStringContainsString('ManagedChallengeRenderer::renderGlobal', $output);
        self::assertStringContainsString('$__csp_nonce ?? null', $output);
        self::assertStringStartsWith('<?php echo', $output);
    }
}
