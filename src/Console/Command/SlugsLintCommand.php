<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Config\I18nConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\I18n\Locale\SlugRegistry;

use function count;
use function implode;
use function sprintf;

/**
 * Validate localized route slugs for completeness and per-locale uniqueness.
 *
 * Designed to run in CI so a missing or ambiguous translation fails the build
 * rather than silently degrading to the untranslated (key) URL at runtime.
 *
 * Checks:
 *  - Completeness — every route key declares a slug for every non-default
 *    supported locale (the default locale is the key itself). Missing slugs are
 *    errors unless `--allow-fallback` downgrades them to warnings.
 *  - Collisions — within a locale, no two keys may resolve to the same slug,
 *    and a slug must not collide with a different key (which would make the
 *    canonical-redirect alias ambiguous).
 */
final class SlugsLintCommand extends Command
{
    public function __construct(
        private readonly SlugRegistry $registry,
        private readonly I18nConfig $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'i18n:slugs:lint';
        $this->description = 'Validate localized route slugs (completeness + collisions)';

        $this->addOption('allow-fallback', 'Treat missing per-locale slugs as warnings, not errors', 'a');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = $this->registry->keys();

        if ($keys === []) {
            $output->success('No localized slugs configured; nothing to lint.');

            return ExitCode::Success->value;
        }

        $allowFallback = $input->hasOption('allow-fallback');

        /** @var list<string> $errors */
        $errors = [];
        /** @var list<string> $warnings */
        $warnings = [];

        $this->checkCompleteness($keys, $allowFallback, $errors, $warnings);
        $this->checkCollisions($keys, $errors);

        foreach ($warnings as $warning) {
            $output->warning($warning);
        }

        foreach ($errors as $error) {
            $output->errorln($error);
        }

        if ($errors !== []) {
            $output->newLine();
            $output->errorln(sprintf('Slug lint failed: %d error(s).', count($errors)));

            return ExitCode::Error->value;
        }

        $output->success(sprintf(
            'Slug lint passed: %d key(s) across %d locale(s)%s.',
            count($keys),
            count($this->config->supportedLocales),
            $warnings !== [] ? sprintf(', %d warning(s)', count($warnings)) : '',
        ));

        return ExitCode::Success->value;
    }

    /**
     * @param list<string> $keys
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkCompleteness(array $keys, bool $allowFallback, array &$errors, array &$warnings): void
    {
        $declared = $this->registry->declaredSlugs();

        foreach ($keys as $key) {
            foreach ($this->config->supportedLocales as $locale) {
                if ($locale === $this->config->defaultLocale) {
                    continue;
                }

                if (!isset($declared[$key][$locale])) {
                    $message = sprintf(
                        'Key "%s" has no slug for locale "%s" (falls back to the key).',
                        $key,
                        $locale,
                    );

                    if ($allowFallback) {
                        $warnings[] = $message;
                    } else {
                        $errors[] = $message;
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param list<string> $errors
     */
    private function checkCollisions(array $keys, array &$errors): void
    {
        foreach ($this->config->supportedLocales as $locale) {
            /** @var array<string, list<string>> $slugToKeys */
            $slugToKeys = [];

            foreach ($keys as $key) {
                $slugToKeys[$this->registry->slugFor($key, $locale)][] = $key;
            }

            foreach ($slugToKeys as $slug => $owners) {
                if (count($owners) > 1) {
                    $errors[] = sprintf(
                        'Locale "%s": slug "%s" is used by multiple keys: %s.',
                        $locale,
                        $slug,
                        implode(', ', $owners),
                    );
                }
            }
        }
    }
}
