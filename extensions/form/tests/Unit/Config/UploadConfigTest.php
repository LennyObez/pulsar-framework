<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\UploadConfig;

final class UploadConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = UploadConfig::fromArray([
            'directory' => '/tmp/uploads',
            'max_size' => 5_000_000,
            'regulated_preset' => true,
        ]);

        self::assertSame('/tmp/uploads', $config->directory);
        self::assertSame(5_000_000, $config->maxSize);
        self::assertTrue($config->regulatedPreset);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = UploadConfig::fromArray([]);

        self::assertSame('storage/uploads', $config->directory);
        self::assertSame(10_485_760, $config->maxSize);
        self::assertFalse($config->regulatedPreset);
    }
}
