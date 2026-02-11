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

#[CoversClass(CsrfFormConfig::class)]
#[CoversClass(RendererConfig::class)]
#[CoversClass(UploadConfig::class)]
#[CoversClass(WizardFormConfig::class)]
#[CoversClass(FormConfig::class)]
final class ConfigClassesTest extends TestCase
{
    // --- CsrfFormConfig ---

    #[Test]
    public function csrfFormConfigFromArrayWithAllFields(): void
    {
        $config = CsrfFormConfig::fromArray([
            'enabled' => false,
            'ttl' => 7200,
            'field_name' => '_token',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(7200, $config->ttl);
        self::assertSame('_token', $config->fieldName);
    }

    #[Test]
    public function csrfFormConfigFromArrayDefaults(): void
    {
        $config = CsrfFormConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(3600, $config->ttl);
        self::assertSame('_csrf_token', $config->fieldName);
    }

    #[Test]
    public function csrfFormConfigIgnoresStringEnabled(): void
    {
        $config = CsrfFormConfig::fromArray(['enabled' => 'yes']);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function csrfFormConfigIgnoresStringTtl(): void
    {
        $config = CsrfFormConfig::fromArray(['ttl' => '3600']);
        self::assertSame(3600, $config->ttl);
    }

    #[Test]
    public function csrfFormConfigIgnoresIntFieldName(): void
    {
        $config = CsrfFormConfig::fromArray(['field_name' => 42]);
        self::assertSame('_csrf_token', $config->fieldName);
    }

    #[Test]
    public function csrfFormConfigIgnoresNullValues(): void
    {
        $config = CsrfFormConfig::fromArray(['enabled' => null, 'ttl' => null, 'field_name' => null]);
        self::assertTrue($config->enabled);
        self::assertSame(3600, $config->ttl);
        self::assertSame('_csrf_token', $config->fieldName);
    }

    // --- RendererConfig ---

    #[Test]
    public function rendererConfigFromArrayWithAllFields(): void
    {
        $config = RendererConfig::fromArray([
            'theme' => 'bootstrap',
            'error_class' => 'error-msg',
            'label_class' => 'label-lg',
            'input_class' => 'input-lg',
            'error_summary_class' => 'summary-error',
        ]);

        self::assertSame('bootstrap', $config->theme);
        self::assertSame('error-msg', $config->errorClass);
        self::assertSame('label-lg', $config->labelClass);
        self::assertSame('input-lg', $config->inputClass);
        self::assertSame('summary-error', $config->errorSummaryClass);
    }

    #[Test]
    public function rendererConfigFromArrayDefaults(): void
    {
        $config = RendererConfig::fromArray([]);

        self::assertSame('default', $config->theme);
        self::assertSame('form-error', $config->errorClass);
        self::assertSame('form-label', $config->labelClass);
        self::assertSame('form-input', $config->inputClass);
        self::assertSame('form-error-summary', $config->errorSummaryClass);
    }

    #[Test]
    public function rendererConfigFromArrayIgnoresNonStringValues(): void
    {
        $config = RendererConfig::fromArray([
            'theme' => 42,
            'error_class' => false,
            'label_class' => null,
            'input_class' => [],
            'error_summary_class' => 3.14,
        ]);

        self::assertSame('default', $config->theme);
        self::assertSame('form-error', $config->errorClass);
        self::assertSame('form-label', $config->labelClass);
        self::assertSame('form-input', $config->inputClass);
        self::assertSame('form-error-summary', $config->errorSummaryClass);
    }

    // --- UploadConfig ---

    #[Test]
    public function uploadConfigFromArrayWithAllFields(): void
    {
        $config = UploadConfig::fromArray([
            'directory' => '/var/uploads',
            'max_size' => 5_242_880,
            'regulated_preset' => true,
        ]);

        self::assertSame('/var/uploads', $config->directory);
        self::assertSame(5_242_880, $config->maxSize);
        self::assertTrue($config->regulatedPreset);
    }

    #[Test]
    public function uploadConfigFromArrayDefaults(): void
    {
        $config = UploadConfig::fromArray([]);

        self::assertSame('storage/uploads', $config->directory);
        self::assertSame(10_485_760, $config->maxSize);
        self::assertFalse($config->regulatedPreset);
    }

    #[Test]
    public function uploadConfigFromArrayIgnoresInvalidTypes(): void
    {
        $config = UploadConfig::fromArray([
            'directory' => 123,
            'max_size' => 'ten',
            'regulated_preset' => 'yes',
        ]);

        self::assertSame('storage/uploads', $config->directory);
        self::assertSame(10_485_760, $config->maxSize);
        self::assertFalse($config->regulatedPreset);
    }

    // --- WizardFormConfig ---

    #[Test]
    public function wizardFormConfigFromArrayWithAllFields(): void
    {
        $config = WizardFormConfig::fromArray([
            'ttl' => 3600,
            'storage' => 'client',
        ]);

        self::assertSame(3600, $config->ttl);
        self::assertSame('client', $config->storage);
    }

    #[Test]
    public function wizardFormConfigFromArrayDefaults(): void
    {
        $config = WizardFormConfig::fromArray([]);

        self::assertSame(1800, $config->ttl);
        self::assertSame('server', $config->storage);
    }

    #[Test]
    public function wizardFormConfigFromArrayIgnoresInvalidTypes(): void
    {
        $config = WizardFormConfig::fromArray([
            'ttl' => 'forever',
            'storage' => 42,
        ]);

        self::assertSame(1800, $config->ttl);
        self::assertSame('server', $config->storage);
    }

    // --- FormConfig (composite) ---

    #[Test]
    public function formConfigFromArrayDelegatesSubConfigs(): void
    {
        $config = FormConfig::fromArray([
            'csrf' => ['enabled' => false, 'ttl' => 900, 'field_name' => 'tok'],
            'renderer' => ['theme' => 'dark'],
            'upload' => ['directory' => '/tmp/u', 'max_size' => 1024],
            'wizard' => ['ttl' => 600, 'storage' => 'redis'],
        ]);

        self::assertFalse($config->csrf->enabled);
        self::assertSame(900, $config->csrf->ttl);
        self::assertSame('tok', $config->csrf->fieldName);
        self::assertSame('dark', $config->renderer->theme);
        self::assertSame('/tmp/u', $config->upload->directory);
        self::assertSame(1024, $config->upload->maxSize);
        self::assertSame(600, $config->wizard->ttl);
        self::assertSame('redis', $config->wizard->storage);
    }

    #[Test]
    public function formConfigFromArrayHandlesNonArraySubKeys(): void
    {
        $config = FormConfig::fromArray([
            'csrf' => 'not-an-array',
            'renderer' => 42,
            'upload' => null,
            'wizard' => false,
        ]);

        // Should fallback to defaults when sub-keys are not arrays
        self::assertTrue($config->csrf->enabled);
        self::assertSame('default', $config->renderer->theme);
        self::assertSame('storage/uploads', $config->upload->directory);
        self::assertSame(1800, $config->wizard->ttl);
    }

    #[Test]
    public function formConfigFromEmptyArray(): void
    {
        $config = FormConfig::fromArray([]);

        self::assertTrue($config->csrf->enabled);
        self::assertSame(3600, $config->csrf->ttl);
        self::assertSame('default', $config->renderer->theme);
        self::assertSame(10_485_760, $config->upload->maxSize);
        self::assertSame('server', $config->wizard->storage);
    }
}
