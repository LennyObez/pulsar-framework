<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpConfig;

#[CoversClass(DlpConfig::class)]
final class DlpConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new DlpConfig();

        self::assertTrue($config->enabled);
        self::assertSame(DlpAction::Redact, $config->defaultAction);
        self::assertSame('*', $config->mask);
        self::assertSame(4, $config->maskSuffixLength);
        self::assertTrue($config->scanLogs);
        self::assertTrue($config->scanResponses);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = DlpConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(DlpAction::Redact, $config->defaultAction);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = DlpConfig::fromArray([
            'enabled' => false,
            'default_action' => 'block',
            'mask' => '#',
            'mask_suffix_length' => 0,
            'scan_logs' => false,
            'scan_responses' => false,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(DlpAction::Block, $config->defaultAction);
        self::assertSame('#', $config->mask);
        self::assertSame(0, $config->maskSuffixLength);
        self::assertFalse($config->scanLogs);
        self::assertFalse($config->scanResponses);
    }

    public function testFromArrayWithAlertAction(): void
    {
        $config = DlpConfig::fromArray(['default_action' => 'alert']);
        self::assertSame(DlpAction::Alert, $config->defaultAction);
    }
}
