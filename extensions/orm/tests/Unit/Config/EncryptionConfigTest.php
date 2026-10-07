<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\EncryptionConfig;

final class EncryptionConfigTest extends TestCase
{
    #[Test]
    public function constructionWithExplicitValues(): void
    {
        $config = new EncryptionConfig(
            enabled: true,
            subKeyId: 7,
            context: 'custom__enc',
            blindIndexContext: 'custom__bidx',
        );

        self::assertTrue($config->enabled);
        self::assertSame(7, $config->subKeyId);
        self::assertSame('custom__enc', $config->context);
        self::assertSame('custom__bidx', $config->blindIndexContext);
    }

    #[Test]
    public function fromArrayWithAllKeys(): void
    {
        $config = EncryptionConfig::fromArray([
            'enabled' => true,
            'sub_key_id' => 10,
            'context' => 'my_ctx',
            'blind_index_context' => 'my_bidx',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(10, $config->subKeyId);
        self::assertSame('my_ctx', $config->context);
        self::assertSame('my_bidx', $config->blindIndexContext);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = EncryptionConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(5, $config->subKeyId);
        self::assertSame('orm__enc', $config->context);
        self::assertSame('orm__bidx', $config->blindIndexContext);
    }
}
