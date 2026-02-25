<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\RendererConfig;

final class RendererConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = RendererConfig::fromArray([
            'theme' => 'dark',
            'error_class' => 'err',
            'label_class' => 'lbl',
            'input_class' => 'inp',
            'error_summary_class' => 'err-sum',
        ]);

        self::assertSame('dark', $config->theme);
        self::assertSame('err', $config->errorClass);
        self::assertSame('lbl', $config->labelClass);
        self::assertSame('inp', $config->inputClass);
        self::assertSame('err-sum', $config->errorSummaryClass);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = RendererConfig::fromArray([]);

        self::assertSame('default', $config->theme);
        self::assertSame('form-error', $config->errorClass);
        self::assertSame('form-label', $config->labelClass);
        self::assertSame('form-input', $config->inputClass);
        self::assertSame('form-error-summary', $config->errorSummaryClass);
    }
}
