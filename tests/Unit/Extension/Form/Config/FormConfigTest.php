<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\CsrfFormConfig;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Config\RendererConfig;
use Pulsar\Extension\Form\Config\UploadConfig;
use Pulsar\Extension\Form\Config\WizardFormConfig;

#[CoversClass(FormConfig::class)]
#[CoversClass(CsrfFormConfig::class)]
#[CoversClass(RendererConfig::class)]
#[CoversClass(UploadConfig::class)]
#[CoversClass(WizardFormConfig::class)]
final class FormConfigTest extends TestCase
{
    #[Test]
    public function it_creates_from_empty_array_with_defaults(): void
    {
        $config = FormConfig::fromArray([]);

        self::assertTrue($config->csrf->enabled);
        self::assertSame(3600, $config->csrf->ttl);
        self::assertSame('_csrf_token', $config->csrf->fieldName);
        self::assertSame('default', $config->renderer->theme);
        self::assertSame('form-error', $config->renderer->errorClass);
        self::assertSame(10_485_760, $config->upload->maxSize);
        self::assertFalse($config->upload->regulatedPreset);
        self::assertSame(1800, $config->wizard->ttl);
        self::assertSame('server', $config->wizard->storage);
    }

    #[Test]
    public function it_creates_from_custom_values(): void
    {
        $config = FormConfig::fromArray([
            'csrf' => [
                'enabled' => false,
                'ttl' => 7200,
                'field_name' => '_token',
            ],
            'renderer' => [
                'theme' => 'bootstrap',
                'error_class' => 'invalid-feedback',
            ],
            'upload' => [
                'max_size' => 5_000_000,
                'regulated_preset' => true,
            ],
            'wizard' => [
                'ttl' => 900,
                'storage' => 'client',
            ],
        ]);

        self::assertFalse($config->csrf->enabled);
        self::assertSame(7200, $config->csrf->ttl);
        self::assertSame('_token', $config->csrf->fieldName);
        self::assertSame('bootstrap', $config->renderer->theme);
        self::assertSame('invalid-feedback', $config->renderer->errorClass);
        self::assertSame(5_000_000, $config->upload->maxSize);
        self::assertTrue($config->upload->regulatedPreset);
        self::assertSame(900, $config->wizard->ttl);
        self::assertSame('client', $config->wizard->storage);
    }

    #[Test]
    public function it_handles_invalid_types_gracefully(): void
    {
        $config = FormConfig::fromArray([
            'csrf' => 'invalid',
            'renderer' => 123,
            'upload' => null,
            'wizard' => false,
        ]);

        // Should fall back to defaults
        self::assertTrue($config->csrf->enabled);
        self::assertSame('default', $config->renderer->theme);
        self::assertSame(10_485_760, $config->upload->maxSize);
        self::assertSame(1800, $config->wizard->ttl);
    }
}
