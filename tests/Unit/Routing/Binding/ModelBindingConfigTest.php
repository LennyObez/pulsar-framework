<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\ModelBindingConfig;

use function dirname;

#[CoversClass(ModelBindingConfig::class)]
final class ModelBindingConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new ModelBindingConfig();

        self::assertSame(ModelBindingConfig::DEFAULT_PRESET, $config->preset);
        self::assertNull($config->authorizationHook);
        self::assertSame(['id', 'uuid', 'slug'], $config->allowedKeyNames);
        self::assertFalse($config->compiledMode);
    }

    #[Test]
    public function sayingNothingSelectsTheEnforcingPresetRatherThanTheOptInOne(): void
    {
        // The constructor default is reached by writing nothing at all — an
        // absent config/model_binding.php, or a bare `new ModelBindingConfig()`
        // at a composition seam — and it used to be the preset under which a
        // request with no authenticated identity binds the model and reaches
        // the controller with no policy check. The shipped config file said
        // 'banking' while this said 'standard', so the two disagreed and the
        // permissive posture was one deleted file away.
        self::assertTrue(
            new ModelBindingConfig()->isRegulatedPreset(),
            'an unconfigured ModelBindingConfig must mandate authorization on every bound model',
        );

        self::assertNotSame(BindingPreset::Standard, ModelBindingConfig::DEFAULT_PRESET);
    }

    #[Test]
    public function theCodeDefaultAndTheShippedConfigFileAgree(): void
    {
        // Both halves of the answer in one assertion. The file is the copy an
        // operator reads; the constant is the copy an application runs when the
        // file is not there.
        /** @var mixed $shipped */
        $shipped = require dirname(__DIR__, 4) . '/config/model_binding.php';

        self::assertIsArray($shipped);
        self::assertArrayHasKey('preset', $shipped);
        self::assertSame(ModelBindingConfig::DEFAULT_PRESET->value, $shipped['preset']);
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

        self::assertSame(BindingPreset::Banking, $config->preset);
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

        self::assertSame(BindingPreset::Healthcare, $config->preset);
        self::assertNull($config->authorizationHook);
        self::assertSame(['id', 'uuid', 'slug'], $config->allowedKeyNames);
        self::assertFalse($config->compiledMode);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = ModelBindingConfig::fromArray([]);

        // An absent key is the same silence as an absent file, and it selects
        // the same enforcing default.
        self::assertSame(ModelBindingConfig::DEFAULT_PRESET, $config->preset);
        self::assertTrue($config->isRegulatedPreset());
        self::assertNull($config->authorizationHook);
        self::assertSame(['id', 'uuid', 'slug'], $config->allowedKeyNames);
        self::assertFalse($config->compiledMode);
    }

    /**
     * The whole point of the change: a preset the framework does not recognize
     * is refused at the moment the config array becomes a config object — during
     * config load, at boot — instead of being carried into the request path as a
     * value that quietly means "permissive".
     */
    #[Test]
    #[DataProvider('misspelledPresets')]
    public function fromArrayRefusesAnUnrecognizedPresetRatherThanFallingBackToThePermissiveOne(mixed $preset): void
    {
        $this->expectException(ConfigException::class);

        (void) ModelBindingConfig::fromArray(['preset' => $preset]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function misspelledPresets(): iterable
    {
        yield 'wrong case' => ['Banking'];
        yield 'typo' => ['bankng'];
        yield 'invented' => ['permissive'];
        yield 'empty string' => [''];
        yield 'not a string' => [true];
    }

    #[Test]
    #[DataProvider('regulatedPresets')]
    public function isRegulatedPresetReturnsTrueForRegulatedPresets(BindingPreset $preset): void
    {
        $config = new ModelBindingConfig(preset: $preset);

        self::assertTrue($config->isRegulatedPreset());
    }

    /**
     * @return iterable<string, array{BindingPreset}>
     */
    public static function regulatedPresets(): iterable
    {
        yield 'banking' => [BindingPreset::Banking];
        yield 'healthcare' => [BindingPreset::Healthcare];
        yield 'legal' => [BindingPreset::Legal];
    }

    #[Test]
    public function isRegulatedPresetReturnsFalseForTheStandardPreset(): void
    {
        $config = new ModelBindingConfig(preset: BindingPreset::Standard);

        self::assertFalse($config->isRegulatedPreset());
    }

    #[Test]
    public function isRegulatedPresetAgreesWithTheEnumItDelegatesTo(): void
    {
        foreach (BindingPreset::cases() as $case) {
            self::assertSame(
                $case->isRegulated(),
                new ModelBindingConfig(preset: $case)->isRegulatedPreset(),
                'The DTO must not carry a second opinion about enforcement.',
            );
        }
    }
}
