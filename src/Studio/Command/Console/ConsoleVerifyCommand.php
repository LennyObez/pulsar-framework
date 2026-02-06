<?php

declare(strict_types=1);

namespace Pulsar\Studio\Command\Console;

use function file_exists;
use function file_get_contents;

use InvalidArgumentException;

use function is_string;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Studio\Console\Evidence\EvidenceVerifier;

use function sprintf;
use function str_repeat;

/**
 * Verifies the integrity of a Studio evidence archive.
 */
#[Internal]
final class ConsoleVerifyCommand extends Command
{
    public function __construct(
        private readonly EvidenceVerifier $verifier,
        private readonly ?string $chainMacKey = null,
        private readonly ?string $archiveMacKey = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'studio:console:verify';
        $this->description = 'Verify a Studio evidence archive';
        $this->addArgument('file', 'Path to the archive file', required: true);
        $this->addOption('mode', 'Verification mode: public, tamper-evident, full', 'm', 'public');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawFilePath = $input->getArgument(0);
        $filePath = is_string($rawFilePath) ? $rawFilePath : '';
        $rawMode = $input->getOption('mode', 'public') ?? 'public';
        $mode = is_string($rawMode) ? $rawMode : 'public';
        $isJson = $input->hasOption('json');

        if (!file_exists($filePath)) {
            if ($isJson) {
                JsonOutputHelper::writeError($output, true, 'file_not_found', 'Archive file not found');
            } else {
                $output->errorln(sprintf('Archive file not found: %s', $filePath));
            }
            return ExitCode::Error->value;
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            $output->errorln('Failed to read archive file');
            return ExitCode::Error->value;
        }

        try {
            $archive = EvidenceArchive::fromJson($contents);
        } catch (InvalidArgumentException $e) {
            if ($isJson) {
                JsonOutputHelper::writeError($output, true, 'invalid_archive', $e->getMessage());
            } else {
                $output->errorln(sprintf('Invalid archive: %s', $e->getMessage()));
            }
            return ExitCode::Error->value;
        }

        // Determine MAC key based on mode
        $macKey = null;
        if ($mode === 'tamper-evident' || $mode === 'full') {
            $macKey = $this->chainMacKey;
            if ($macKey === null) {
                JsonOutputHelper::writeError(
                    $output,
                    $isJson,
                    'mac_key_required',
                    'Tamper-evident verification requires the chain MAC key (PULSAR_MASTER_KEY must be set)',
                );
                return ExitCode::Error->value;
            }
        }

        // Verify the chain
        $result = $this->verifier->verify(
            chainLinks: $this->mergeChainWithEvents($archive),
            chainMacKey: $macKey,
        );

        // Verify archive MAC in full mode
        if ($mode === 'full' && $this->archiveMacKey !== null) {
            $result['archive_mac_verified'] = $this->verifier->verifyArchiveMac($archive, $this->archiveMacKey);
        }

        $result['verification_mode'] = $mode;

        if ($isJson) {
            $output->writeln(JsonOutputHelper::formatJson($result));
            return $result['chain_intact'] ? ExitCode::Success->value : ExitCode::Error->value;
        }

        $this->outputHumanReadable($result, $output);

        return $result['chain_intact'] ? ExitCode::Success->value : ExitCode::Error->value;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function outputHumanReadable(array $result, OutputInterface $output): void
    {
        $output->writeln('Evidence Chain Verification');
        $output->writeln(str_repeat('=', 30));
        $output->newLine();

        /** @var string $verificationMode */
        $verificationMode = $result['verification_mode'] ?? '';
        /** @var string $chainMode */
        $chainMode = $result['mode'] ?? '';
        /** @var string $anchorType */
        $anchorType = $result['anchor_type'] ?? '';
        /** @var int $linksVerified */
        $linksVerified = $result['links_verified'] ?? 0;
        /** @var int $linksPruned */
        $linksPruned = $result['links_pruned'] ?? 0;

        $this->writeField($output, 'Mode', $verificationMode);
        $this->writeField($output, 'Chain mode', $chainMode);
        $this->writeField($output, 'Anchor', $anchorType);
        $this->writeField($output, 'Links', sprintf('%d verified', $linksVerified));

        if ($linksPruned > 0) {
            $this->writeField($output, 'Pruned', sprintf('%d links (by retention)', $linksPruned));
        }

        $output->newLine();

        if ($result['chain_intact']) {
            $output->success('Chain integrity verified.');

            if ($chainMode === 'window') {
                /** @var string $earliestEventId */
                $earliestEventId = $result['earliest_event_id'] ?? '';
                /** @var string $latestEventId */
                $latestEventId = $result['latest_event_id'] ?? '';
                $output->writeln(sprintf(
                    '  Verified %d links from %s to %s.',
                    $linksVerified,
                    $earliestEventId,
                    $latestEventId,
                ));
                $output->writeln('  Note: Earlier links were pruned by retention. The window anchor is the trust boundary.');
            }
        } else {
            $output->errorln('Chain integrity FAILED.');

            /** @var list<array{index: int, event_id: string, reason: string}> $failures */
            $failures = $result['failures'] ?? [];
            foreach ($failures as $failure) {
                $output->writeln(sprintf('  Break at link %d (event %s): %s', $failure['index'], $failure['event_id'], $failure['reason']));
            }
        }

        if (isset($result['mac_verified'])) {
            $output->newLine();
            $this->writeField($output, 'MAC verified', $result['mac_verified'] ? 'Yes' : 'FAILED');
        }

        if (isset($result['archive_mac_verified'])) {
            $this->writeField($output, 'Archive MAC', $result['archive_mac_verified'] ? 'Verified' : 'FAILED');
        }
    }

    /**
     * Write a labeled key-value field to console output.
     */
    private function writeField(OutputInterface $output, string $label, string $value): void
    {
        $output->writeln(sprintf('  %-14s %s', $label . ':', $value));
    }

    /**
     * Merge chain links with event data for verification.
     *
     * @return list<array<string, mixed>>
     */
    private function mergeChainWithEvents(EvidenceArchive $archive): array
    {
        $eventsByEventId = [];
        foreach ($archive->events as $event) {
            /** @var string $eventId */
            $eventId = $event['event_id'] ?? '';
            $eventsByEventId[$eventId] = $event;
        }

        return array_map(
            static function (array $link) use ($eventsByEventId): array {
                /** @var string $linkEventId */
                $linkEventId = $link['event_id'] ?? '';

                return [...$link, ...($eventsByEventId[$linkEventId] ?? [])];
            },
            $archive->chainLinks,
        );
    }
}
