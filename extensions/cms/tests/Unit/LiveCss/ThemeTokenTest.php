<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\ThemeToken;

#[CoversClass(ThemeToken::class)]
final class ThemeTokenTest extends TestCase
{
    #[Test]
    public function stores_all_properties(): void
    {
        $token = new ThemeToken(
            name: '--color-primary',
            type: 'color',
            default: '#3b82f6',
            label: 'Primary Color',
            group: 'Colors',
            constraints: ['format' => 'hex'],
        );

        self::assertSame('--color-primary', $token->name);
        self::assertSame('color', $token->type);
        self::assertSame('#3b82f6', $token->default);
        self::assertSame('Primary Color', $token->label);
        self::assertSame('Colors', $token->group);
        self::assertSame(['format' => 'hex'], $token->constraints);
    }

    #[Test]
    public function constraints_default_to_empty_array(): void
    {
        $token = new ThemeToken(
            name: '--font-body',
            type: 'font',
            default: 'Inter',
            label: 'Body Font',
            group: 'Typography',
        );

        self::assertSame([], $token->constraints);
    }
}
