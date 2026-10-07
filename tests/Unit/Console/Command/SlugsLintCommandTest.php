<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\Console\Command\SlugsLintCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\I18n\Locale\LocaleUrlStrategy;
use Pulsar\I18n\Locale\SlugRegistry;

#[CoversClass(SlugsLintCommand::class)]
final class SlugsLintCommandTest extends TestCase
{
    /**
     * @param array<string, array<string, string>> $slugs
     * @param list<string> $supportedLocales
     */
    private function makeCommand(array $slugs, array $supportedLocales = ['en', 'fr']): SlugsLintCommand
    {
        $config = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: $supportedLocales,
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
            urlStrategy: LocaleUrlStrategy::PathPrefix,
            defaultLocaleInUrl: false,
            canonicalRedirect: true,
            localizedSlugs: $slugs,
        );

        return new SlugsLintCommand(SlugRegistry::fromConfig($slugs, $supportedLocales), $config);
    }

    /**
     * @return array{exit: int, out: string}
     */
    private function runLint(SlugsLintCommand $command, bool $allowFallback = false): array
    {
        $output = new BufferedOutput();
        $options = $allowFallback ? ['allow-fallback' => true] : [];
        $exit = $command->execute(new ArrayInput('i18n:slugs:lint', [], $options), $output);

        // Errors go to errorBuffer, success/warnings to buffer — combine both.
        return ['exit' => $exit, 'out' => $output->buffer . $output->errorBuffer];
    }

    #[Test]
    public function configured_with_correct_name(): void
    {
        $command = $this->makeCommand([]);

        self::assertSame('i18n:slugs:lint', $command->name);
        self::assertArrayHasKey('allow-fallback', $command->options);
    }

    #[Test]
    public function passes_with_no_slugs_configured(): void
    {
        $result = $this->runLint($this->makeCommand([]));

        self::assertSame(ExitCode::Success->value, $result['exit']);
        self::assertStringContainsString('nothing to lint', $result['out']);
    }

    #[Test]
    public function passes_for_complete_collision_free_config(): void
    {
        $command = $this->makeCommand([
            'development' => ['fr' => 'developpement'],
            'about' => ['fr' => 'a-propos'],
        ]);

        $result = $this->runLint($command);

        self::assertSame(ExitCode::Success->value, $result['exit']);
        self::assertStringContainsString('Slug lint passed', $result['out']);
    }

    #[Test]
    public function fails_on_missing_locale_slug(): void
    {
        $command = $this->makeCommand([
            'development' => ['fr' => 'developpement'],
            'about' => ['de' => 'ueber'], // no fr slug
        ], ['en', 'fr']);

        $result = $this->runLint($command);

        self::assertSame(ExitCode::Error->value, $result['exit']);
        self::assertStringContainsString('no slug for locale "fr"', $result['out']);
    }

    #[Test]
    public function allow_fallback_downgrades_missing_to_warning(): void
    {
        $command = $this->makeCommand([
            'development' => ['fr' => 'developpement'],
            'about' => ['de' => 'ueber'],
        ], ['en', 'fr']);

        $result = $this->runLint($command, allowFallback: true);

        self::assertSame(ExitCode::Success->value, $result['exit']);
        self::assertStringContainsString('Slug lint passed', $result['out']);
    }

    #[Test]
    public function fails_on_per_locale_slug_collision(): void
    {
        $command = $this->makeCommand([
            'development' => ['fr' => 'travail'],
            'work' => ['fr' => 'travail'], // collision in fr
        ], ['en', 'fr']);

        $result = $this->runLint($command);

        self::assertSame(ExitCode::Error->value, $result['exit']);
        self::assertStringContainsString('used by multiple keys', $result['out']);
    }
}
