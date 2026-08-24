<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Routing\Binding\BindingPreset;

use function dirname;

/**
 * The preset picks between "authorization is mandatory on every bound model"
 * and "authorization is opt-in". It used to be a free-form string matched with
 * a strict, case-sensitive in_array, so `Banking` and `bankng` both missed the
 * regulated branch and selected the permissive one — a security posture turning
 * on a spelling, and failing OPEN when the spelling was wrong.
 *
 * These tests pin the two halves of the answer:
 *
 *  - inside PHP the value is a closed type, so an invalid preset cannot be
 *    written down at all;
 *  - at the config-file boundary, where a string is all there is, an
 *    unrecognized value is REFUSED rather than defaulted.
 *
 * The second half is the one that matters. An enum whose config conversion read
 * `tryFrom($value) ?? self::Standard` would reproduce the original defect
 * exactly, one layer down.
 */
#[CoversClass(BindingPreset::class)]
final class BindingPresetTest extends TestCase
{
    // -----------------------------------------------------------------
    // Valid presets
    // -----------------------------------------------------------------

    #[Test]
    #[DataProvider('everyPreset')]
    public function fromConfigAcceptsEveryShippedName(string $value, BindingPreset $expected): void
    {
        self::assertSame($expected, BindingPreset::fromConfig($value));
    }

    /**
     * @return iterable<string, array{string, BindingPreset}>
     */
    public static function everyPreset(): iterable
    {
        yield 'banking' => ['banking', BindingPreset::Banking];
        yield 'healthcare' => ['healthcare', BindingPreset::Healthcare];
        yield 'legal' => ['legal', BindingPreset::Legal];
        yield 'standard' => ['standard', BindingPreset::Standard];
    }

    #[Test]
    public function everyCaseIsReachableFromItsOwnValue(): void
    {
        // Guards the pair of lists this class keeps: a case whose value the
        // config boundary refuses would be a preset nobody could configure.
        foreach (BindingPreset::cases() as $case) {
            self::assertSame($case, BindingPreset::fromConfig($case->value));
        }
    }

    // -----------------------------------------------------------------
    // Invalid presets — refused, never defaulted
    // -----------------------------------------------------------------

    #[Test]
    #[DataProvider('invalidPresets')]
    public function fromConfigRefusesAnythingItDoesNotRecognize(mixed $value): void
    {
        $this->expectException(ConfigException::class);

        (void) BindingPreset::fromConfig($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPresets(): iterable
    {
        // The two spellings the adversarial review used. Both used to select the
        // permissive path in silence.
        yield 'wrong case' => ['Banking'];
        yield 'typo' => ['bankng'];

        yield 'upper case' => ['BANKING'];
        yield 'padded' => [' banking'];
        yield 'plural' => ['banks'];
        yield 'invented' => ['permissive'];
        yield 'empty string' => [''];

        // A config file is PHP: `'preset' => true` is as writable as a typo, and
        // a TypeError names the type rather than the setting.
        yield 'bool' => [true];
        yield 'int' => [1];
        yield 'null' => [null];
        yield 'array' => [['banking']];
    }

    #[Test]
    public function theRefusalNamesTheOffendingValueTheSettingAndTheValidSet(): void
    {
        try {
            (void) BindingPreset::fromConfig('bankng');
            self::fail('An unknown preset must be refused.');
        } catch (ConfigException $e) {
            $message = $e->getMessage();

            self::assertStringContainsString('bankng', $message, 'The operator must see what they wrote.');
            self::assertStringContainsString('preset', $message);
            self::assertStringContainsString('config/model_binding.php', $message);

            foreach (BindingPreset::cases() as $case) {
                self::assertStringContainsString($case->value, $message, 'Every valid value must be listed.');
            }
        }
    }

    #[Test]
    public function theRefusalRendersANonStringWithoutTrippingOverIt(): void
    {
        try {
            (void) BindingPreset::fromConfig(['banking']);
            self::fail('An unknown preset must be refused.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('array', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Enforcement level
    // -----------------------------------------------------------------

    #[Test]
    public function standardIsTheOnlyPresetThatMakesAuthorizationOptional(): void
    {
        self::assertFalse(BindingPreset::Standard->isRegulated());

        foreach (BindingPreset::cases() as $case) {
            if ($case === BindingPreset::Standard) {
                continue;
            }

            self::assertTrue(
                $case->isRegulated(),
                'A preset added to this enum must enforce until someone deliberately exempts it.',
            );
        }
    }

    #[Test]
    #[DataProvider('regulatedPresets')]
    public function theRegulatedNamesStillBehaveAsDocumented(BindingPreset $preset): void
    {
        // Documented behaviour: the three regulated names differ in NO
        // behaviour. The name records which regime you answer to; the value
        // picks one of the two enforcement levels.
        self::assertTrue($preset->isRegulated());
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

    // -----------------------------------------------------------------
    // The shipped file
    // -----------------------------------------------------------------

    #[Test]
    public function theShippedConfigFileParsesAndEnforces(): void
    {
        /** @var mixed $shipped */
        $shipped = require dirname(__DIR__, 4) . '/config/model_binding.php';

        self::assertIsArray($shipped);
        self::assertArrayHasKey('preset', $shipped);

        $preset = BindingPreset::fromConfig($shipped['preset']);

        self::assertTrue(
            $preset->isRegulated(),
            'config/model_binding.php must ship a preset that makes authorization mandatory on every bound model.',
        );
    }
}
