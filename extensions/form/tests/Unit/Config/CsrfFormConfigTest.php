<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\CsrfFormConfig;

final class CsrfFormConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = CsrfFormConfig::fromArray([
            'enabled' => false,
            'ttl' => 7200,
            'field_name' => '_custom_token',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(7200, $config->ttl);
        self::assertSame('_custom_token', $config->fieldName);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = CsrfFormConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(3600, $config->ttl);
        self::assertSame('_csrf_token', $config->fieldName);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = CsrfFormConfig::fromArray([
            'enabled' => 'yes',
            'ttl' => 'invalid',
            'field_name' => 123,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(3600, $config->ttl);
        self::assertSame('_csrf_token', $config->fieldName);
    }
}
