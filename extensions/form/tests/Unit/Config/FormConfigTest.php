<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\FormConfig;

final class FormConfigTest extends TestCase
{
    #[Test]
    public function fromArrayDelegatesSubConfigs(): void
    {
        $config = FormConfig::fromArray([
            'csrf' => ['enabled' => false, 'ttl' => 600],
            'renderer' => ['theme' => 'bootstrap'],
            'upload' => ['max_size' => 1_000_000],
            'wizard' => ['ttl' => 300],
        ]);

        self::assertFalse($config->csrf->enabled);
        self::assertSame(600, $config->csrf->ttl);
        self::assertSame('bootstrap', $config->renderer->theme);
        self::assertSame(1_000_000, $config->upload->maxSize);
        self::assertSame(300, $config->wizard->ttl);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingSubConfigs(): void
    {
        $config = FormConfig::fromArray([]);

        self::assertTrue($config->csrf->enabled);
        self::assertSame('default', $config->renderer->theme);
        self::assertSame('storage/uploads', $config->upload->directory);
        self::assertSame('server', $config->wizard->storage);
    }

    #[Test]
    public function fromArrayIgnoresNonArraySubConfigs(): void
    {
        $config = FormConfig::fromArray([
            'csrf' => 'invalid',
            'renderer' => 42,
            'upload' => null,
            'wizard' => true,
        ]);

        self::assertTrue($config->csrf->enabled);
        self::assertSame('default', $config->renderer->theme);
    }
}
