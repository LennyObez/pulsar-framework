<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\WizardFormConfig;

final class WizardFormConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = WizardFormConfig::fromArray([
            'ttl' => 900,
            'storage' => 'client',
        ]);

        self::assertSame(900, $config->ttl);
        self::assertSame('client', $config->storage);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = WizardFormConfig::fromArray([]);

        self::assertSame(1800, $config->ttl);
        self::assertSame('server', $config->storage);
    }
}
