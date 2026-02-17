<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Waf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Waf\WafConfig;

#[CoversClass(WafConfig::class)]
final class WafConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new WafConfig();

        self::assertTrue($config->enabled);
        self::assertSame(1, $config->paranoiaLevel);
        self::assertSame([], $config->bypassIps);
        self::assertNull($config->customRulesPath);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = WafConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(1, $config->paranoiaLevel);
        self::assertSame([], $config->bypassIps);
        self::assertNull($config->customRulesPath);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = WafConfig::fromArray([
            'enabled' => false,
            'paranoia_level' => 3,
            'bypass_ips' => ['10.0.0.1', '192.168.1.1'],
            'custom_rules_path' => '/etc/waf/custom.php',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(3, $config->paranoiaLevel);
        self::assertSame(['10.0.0.1', '192.168.1.1'], $config->bypassIps);
        self::assertSame('/etc/waf/custom.php', $config->customRulesPath);
    }

    #[DataProvider('paranoiaClampProvider')]
    public function testFromArrayClampsParanoiaLevel(int $input, int $expected): void
    {
        $config = WafConfig::fromArray(['paranoia_level' => $input]);
        self::assertSame($expected, $config->paranoiaLevel);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function paranoiaClampProvider(): iterable
    {
        yield 'below minimum' => [0, 1];
        yield 'at minimum' => [1, 1];
        yield 'valid middle' => [2, 2];
        yield 'at maximum' => [4, 4];
        yield 'above maximum' => [10, 4];
        yield 'negative' => [-1, 1];
    }

    public function testFromArrayIgnoresInvalidBypassIps(): void
    {
        $config = WafConfig::fromArray(['bypass_ips' => 'not-an-array']);
        self::assertSame([], $config->bypassIps);
    }

    public function testFromArrayIgnoresInvalidCustomRulesPath(): void
    {
        $config = WafConfig::fromArray(['custom_rules_path' => 42]);
        self::assertNull($config->customRulesPath);
    }
}
