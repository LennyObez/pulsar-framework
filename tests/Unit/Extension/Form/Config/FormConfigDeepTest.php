<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\CsrfFormConfig;
use Pulsar\Extension\Form\Config\RendererConfig;
use Pulsar\Extension\Form\Config\UploadConfig;
use Pulsar\Extension\Form\Config\WizardFormConfig;

#[CoversClass(CsrfFormConfig::class)]
#[CoversClass(RendererConfig::class)]
#[CoversClass(UploadConfig::class)]
#[CoversClass(WizardFormConfig::class)]
final class FormConfigDeepTest extends TestCase
{
    // --- CsrfFormConfig ---

    #[Test]
    public function csrfDefaultValues(): void
    {
        $config = CsrfFormConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(3600, $config->ttl);
        self::assertSame('_csrf_token', $config->fieldName);
    }

    #[Test]
    public function csrfCustomValues(): void
    {
        $config = CsrfFormConfig::fromArray([
            'enabled' => false,
            'ttl' => 1800,
            'field_name' => '_token',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(1800, $config->ttl);
        self::assertSame('_token', $config->fieldName);
    }

    #[Test]
    public function csrfNonBoolEnabledDefaultsToTrue(): void
    {
        $config = CsrfFormConfig::fromArray(['enabled' => 'yes']);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function csrfNonIntTtlDefaultsTo3600(): void
    {
        $config = CsrfFormConfig::fromArray(['ttl' => 'bad']);

        self::assertSame(3600, $config->ttl);
    }

    #[Test]
    public function csrfNonStringFieldNameDefaultsToToken(): void
    {
        $config = CsrfFormConfig::fromArray(['field_name' => 123]);

        self::assertSame('_csrf_token', $config->fieldName);
    }

    // --- RendererConfig ---

    #[Test]
    public function rendererDefaultValues(): void
    {
        $config = RendererConfig::fromArray([]);

        self::assertSame('default', $config->theme);
        self::assertSame('form-error', $config->errorClass);
        self::assertSame('form-label', $config->labelClass);
        self::assertSame('form-input', $config->inputClass);
        self::assertSame('form-error-summary', $config->errorSummaryClass);
    }

    #[Test]
    public function rendererCustomValues(): void
    {
        $config = RendererConfig::fromArray([
            'theme' => 'bootstrap',
            'error_class' => 'is-invalid',
            'label_class' => 'col-form-label',
            'input_class' => 'form-control',
            'error_summary_class' => 'alert-danger',
        ]);

        self::assertSame('bootstrap', $config->theme);
        self::assertSame('is-invalid', $config->errorClass);
        self::assertSame('col-form-label', $config->labelClass);
        self::assertSame('form-control', $config->inputClass);
        self::assertSame('alert-danger', $config->errorSummaryClass);
    }

    #[Test]
    public function rendererNonStringFallsBackToDefaults(): void
    {
        $config = RendererConfig::fromArray([
            'theme' => 123,
            'error_class' => false,
            'label_class' => [],
            'input_class' => null,
            'error_summary_class' => 42,
        ]);

        self::assertSame('default', $config->theme);
        self::assertSame('form-error', $config->errorClass);
        self::assertSame('form-label', $config->labelClass);
        self::assertSame('form-input', $config->inputClass);
        self::assertSame('form-error-summary', $config->errorSummaryClass);
    }

    // --- UploadConfig ---

    #[Test]
    public function uploadDefaultValues(): void
    {
        $config = UploadConfig::fromArray([]);

        self::assertSame('storage/uploads', $config->directory);
        self::assertSame(10_485_760, $config->maxSize);
        self::assertFalse($config->regulatedPreset);
    }

    #[Test]
    public function uploadCustomValues(): void
    {
        $config = UploadConfig::fromArray([
            'directory' => '/var/uploads',
            'max_size' => 5_000_000,
            'regulated_preset' => true,
        ]);

        self::assertSame('/var/uploads', $config->directory);
        self::assertSame(5_000_000, $config->maxSize);
        self::assertTrue($config->regulatedPreset);
    }

    #[Test]
    public function uploadNonStringDirectoryFallsBack(): void
    {
        $config = UploadConfig::fromArray(['directory' => 123]);

        self::assertSame('storage/uploads', $config->directory);
    }

    #[Test]
    public function uploadNonIntMaxSizeFallsBack(): void
    {
        $config = UploadConfig::fromArray(['max_size' => 'big']);

        self::assertSame(10_485_760, $config->maxSize);
    }

    #[Test]
    public function uploadNonBoolRegulatedPresetFallsBack(): void
    {
        $config = UploadConfig::fromArray(['regulated_preset' => 'yes']);

        self::assertFalse($config->regulatedPreset);
    }

    // --- WizardFormConfig ---

    #[Test]
    public function wizardDefaultValues(): void
    {
        $config = WizardFormConfig::fromArray([]);

        self::assertSame(1800, $config->ttl);
        self::assertSame('server', $config->storage);
    }

    #[Test]
    public function wizardCustomValues(): void
    {
        $config = WizardFormConfig::fromArray([
            'ttl' => 7200,
            'storage' => 'redis',
        ]);

        self::assertSame(7200, $config->ttl);
        self::assertSame('redis', $config->storage);
    }

    #[Test]
    public function wizardNonIntTtlFallsBack(): void
    {
        $config = WizardFormConfig::fromArray(['ttl' => 'long']);

        self::assertSame(1800, $config->ttl);
    }

    #[Test]
    public function wizardNonStringStorageFallsBack(): void
    {
        $config = WizardFormConfig::fromArray(['storage' => false]);

        self::assertSame('server', $config->storage);
    }
}
