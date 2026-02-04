<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function in_array;
use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

/**
 * Exports project metadata as JSON to stdout.
 *
 * Applies SensitiveDataScrubber to all output. Warnings are written to stderr.
 */
final class MetadataExportCommand extends Command
{
    private const array VALID_SECTIONS = [
        'all',
        'api',
        'architecture',
        'config-schema',
        'commands',
        'routes',
    ];

    public function __construct(
        private readonly ProjectMetadataService $metadataService,
        private readonly SensitiveDataScrubber $scrubber,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'metadata:export';
        $this->description = 'Export project metadata as JSON';

        $this->addOption(
            'section',
            'Which section to export (api|architecture|config-schema|commands|routes|all)',
            's',
            'all',
        );
        $this->addOption(
            'pretty',
            'Pretty-print the JSON output',
        );
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $sectionRaw = $input->getOption('section');
        $section = is_string($sectionRaw) ? $sectionRaw : 'all';
        $pretty = $input->hasOption('pretty');

        if (!in_array($section, self::VALID_SECTIONS, true)) {
            $output->errorln('Invalid section: ' . $section);
            $output->errorln('Valid sections: ' . implode(', ', self::VALID_SECTIONS));

            return ExitCode::Invalid->value;
        }

        $snapshot = $this->metadataService->snapshot();
        $data = $this->extractSection($snapshot->toArray(), $section);

        // Final scrub pass
        /** @var array<string, mixed> $data */
        $scrubbed = $this->scrubber->scrub($data);

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($scrubbed, $flags);
        $output->writeln($json);

        // Write warnings to stderr
        if ($snapshot->warnings !== []) {
            foreach ($snapshot->warnings as $warning) {
                $output->errorln('[warning] ' . $warning);
            }
        }

        return ExitCode::Success->value;
    }

    /**
     * Extract the requested section from the full snapshot array.
     *
     * @param array<string, mixed> $snapshotArray
     *
     * @return array<string, mixed>
     */
    private function extractSection(array $snapshotArray, string $section): array
    {
        return match ($section) {
            'api' => ['api_snapshot' => $snapshotArray['api_snapshot'] ?? []],
            'architecture' => ['architecture_map' => $snapshotArray['architecture_map'] ?? []],
            'config-schema' => ['config_schema' => $snapshotArray['config_schema'] ?? []],
            'commands' => ['command_reference' => $snapshotArray['command_reference'] ?? []],
            'routes' => ['route_map' => $snapshotArray['route_map'] ?? []],
            default => $snapshotArray,
        };
    }
}
