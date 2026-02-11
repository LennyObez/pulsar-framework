<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Transport\Config\PostmarkTransportConfig;

#[CoversClass(PostmarkTransportConfig::class)]
final class PostmarkTransportConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new PostmarkTransportConfig();

        self::assertSame('', $config->serverToken);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = PostmarkTransportConfig::fromArray([
            'server_token' => 'pm-tok-abc123def456ghi789jkl012mno345',
        ]);

        self::assertSame('pm-tok-abc123def456ghi789jkl012mno345', $config->serverToken);
    }

    #[Test]
    public function fromArrayWithInvalidTypeUsesDefault(): void
    {
        $config = PostmarkTransportConfig::fromArray([
            'server_token' => 12345,
        ]);

        self::assertSame('', $config->serverToken);
    }
}
