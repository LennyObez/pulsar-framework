<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\ModelBindingConfig;

#[CoversClass(ModelBindingConfig::class)]
final class ModelBindingConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new ModelBindingConfig();

        self::assertSame('standard', $config->preset);
        self::assertNull($config->authorizationHook);
        self::assertSame(['id', 'uuid', 'slug'], $config->allowedKeyNames);
        self::assertFalse($config->compiledMode);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = ModelBindingConfig::fromArray([
            'preset' => 'banking',
            'authorization_hook' => 'App\\Auth\\CustomHook',
            'allowed_key_names' => ['id', 'uuid'],
            'compiled_mode' => true,
        ]);

        self::assertSame('banking', $config->preset);
        self::assertSame('App\\Auth\\CustomHook', $config->authorizationHook);
        self::assertSame(['id', 'uuid'], $config->allowedKeyNames);
        self::assertTrue($config->compiledMode);
    }

    #[Test]
    public function fromArrayWithPartialData(): void
    {
        $config = ModelBindingConfig::fromArray([
            'preset' => 'healthcare',
        ]);

        self::assertSame('healthcare', $config->preset);
        self::assertNull($config->authorizationHook);
        self::assertSame(['id', 'uuid', 'slug'], $config->allowedKeyNames);
        self::assertFalse($config->compiledMode);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = ModelBindingConfig::fromArray([]);

        self::assertSame('standard', $config->preset);
        self::assertNull($config->authorizationHook);
        self::assertSame(['id', 'uuid', 'slug'], $config->allowedKeyNames);
        self::assertFalse($config->compiledMode);
    }

    #[Test]
    #[DataProvider('regulatedPresets')]
    public function isRegulatedPresetReturnsTrueForRegulatedPresets(string $preset): void
    {
        $config = new ModelBindingConfig(preset: $preset);

        self::assertTrue($config->isRegulatedPreset());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function regulatedPresets(): iterable
    {
        yield 'banking' => ['banking'];
        yield 'healthcare' => ['healthcare'];
        yield 'legal' => ['legal'];
    }

    #[Test]
    #[DataProvider('nonRegulatedPresets')]
    public function isRegulatedPresetReturnsFalseForNonRegulatedPresets(string $preset): void
    {
        $config = new ModelBindingConfig(preset: $preset);

        self::assertFalse($config->isRegulatedPreset());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonRegulatedPresets(): iterable
    {
        yield 'standard' => ['standard'];
        yield 'custom' => ['custom'];
        yield 'permissive' => ['permissive'];
    }
}
