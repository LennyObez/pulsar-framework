<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ThreatDetection\HoneypotConfig;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(HoneypotConfig::class)]
final class HoneypotConfigTest extends TestCase
{
    public function testDefaultPaths(): void
    {
        $config = new HoneypotConfig();

        self::assertNotEmpty($config->paths);
        self::assertContains('/wp-login.php', $config->paths);
        self::assertContains('/.env', $config->paths);
        self::assertContains('/.git/config', $config->paths);
    }

    public function testCustomPaths(): void
    {
        $config = new HoneypotConfig(paths: ['/custom/trap', '/hidden/admin']);

        self::assertSame(['/custom/trap', '/hidden/admin'], $config->paths);
        self::assertNotContains('/wp-login.php', $config->paths);
    }

    public function testDefaultBlockIp(): void
    {
        $config = new HoneypotConfig();
        self::assertTrue($config->blockIp);
    }

    public function testDefaultResponseAction(): void
    {
        $config = new HoneypotConfig();
        self::assertSame(ThreatResponse::Block, $config->responseAction);
    }

    public function testCustomResponseAction(): void
    {
        $config = new HoneypotConfig(responseAction: ThreatResponse::Alert);
        self::assertSame(ThreatResponse::Alert, $config->responseAction);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = HoneypotConfig::fromArray([]);

        self::assertNotEmpty($config->paths);
        self::assertTrue($config->blockIp);
        self::assertSame(ThreatResponse::Block, $config->responseAction);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = HoneypotConfig::fromArray([
            'paths' => ['/trap1', '/trap2'],
            'block_ip' => false,
            'response_action' => 'alert',
        ]);

        self::assertSame(['/trap1', '/trap2'], $config->paths);
        self::assertFalse($config->blockIp);
        self::assertSame(ThreatResponse::Alert, $config->responseAction);
    }

    public function testFromArrayWithInvalidPaths(): void
    {
        $config = HoneypotConfig::fromArray(['paths' => 'not-an-array']);

        // Falls back to default paths when paths is not an array
        self::assertNotEmpty($config->paths);
        self::assertContains('/wp-login.php', $config->paths);
    }

    public function testFromArrayWithThreatResponseEnum(): void
    {
        $config = HoneypotConfig::fromArray([
            'response_action' => ThreatResponse::RateLimit,
        ]);

        self::assertSame(ThreatResponse::RateLimit, $config->responseAction);
    }

    public function testFromArrayWithInvalidResponseAction(): void
    {
        $config = HoneypotConfig::fromArray([
            'response_action' => 'invalid_action',
        ]);

        // Falls back to Block for invalid action string
        self::assertSame(ThreatResponse::Block, $config->responseAction);
    }

    #[DataProvider('blockIpVariationsProvider')]
    public function testFromArrayBlockIpVariations(mixed $input, bool $expected): void
    {
        $config = HoneypotConfig::fromArray(['block_ip' => $input]);
        self::assertSame($expected, $config->blockIp);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function blockIpVariationsProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
    }
}
