<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\I18n\Extractor\TranslationExtractor;

use function dirname;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Extracts translation keys from PHP source files.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class I18nExtractCommand extends Command
{
    public function __construct(
        private readonly TranslationExtractor $extractor,
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'i18n:extract';
        $this->description = 'Extract translation keys from PHP source files';
        $this->addOption('output', 'Output file path', '-o', 'var/i18n/extracted.json');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $srcDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'src';
        /** @var string $outputPath */
        $outputPath = $input->getOption('output', 'var/i18n/extracted.json');

        $output->info('Scanning for translation keys...');

        $result = $this->extractor->extract($srcDir, $this->projectRoot);

        $absoluteOutput = $this->projectRoot . DIRECTORY_SEPARATOR . $outputPath;
        $dir = dirname($absoluteOutput);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        $json = json_encode(
            $result->keys,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        file_put_contents($absoluteOutput, $json . "\n");

        $output->success(sprintf(
            'Extracted %d keys to %s',
            $result->totalKeys(),
            $outputPath,
        ));

        return ExitCode::Success->value;
    }
}
