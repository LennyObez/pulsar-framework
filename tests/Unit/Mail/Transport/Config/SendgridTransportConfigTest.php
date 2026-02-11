<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Transport\Config\SendgridTransportConfig;

#[CoversClass(SendgridTransportConfig::class)]
final class SendgridTransportConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new SendgridTransportConfig();

        self::assertSame('', $config->apiKey);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = SendgridTransportConfig::fromArray([
            'api_key' => 'SG.abc123def456.ghi789jkl012mno345pqr678',
        ]);

        self::assertSame('SG.abc123def456.ghi789jkl012mno345pqr678', $config->apiKey);
    }

    #[Test]
    public function fromArrayWithInvalidTypeUsesDefault(): void
    {
        $config = SendgridTransportConfig::fromArray([
            'api_key' => null,
        ]);

        self::assertSame('', $config->apiKey);
    }
}
