<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Command\Console\Evidence;

use InvalidArgumentException;
use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceVerifier;
use SodiumException;

use function file_exists;
use function file_get_contents;
use function is_string;
use function json_encode;
use function sprintf;
use function str_repeat;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Verifies the integrity of a Studio evidence archive.
 *
 * Replaces the old `studio:console:verify` command.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class EvidenceVerifyCommand extends Command
{
    public function __construct(
        private readonly EvidenceVerifier $verifier,
        private readonly ?string $chainMacKey = null,
        private readonly ?string $archiveMacKey = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'studio:console:evidence:verify';
        $this->description = 'Verify a Studio evidence archive';
        $this->addArgument('file', 'Path to the archive file', required: true);
        $this->addOption('mode', 'Verification mode: public, tamper-evident, full', 'm', 'public');
        $this->addOption('json', 'Output as JSON', 'j');
    }

    /**
     * @throws JsonException
     * @throws SodiumException If MAC verification fails
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var mixed $rawFilePath */
        $rawFilePath = $input->getArgument(0);
        $filePath = is_string($rawFilePath) ? $rawFilePath : '';
        $mode = $input->getStringOption('mode', 'public');
        $isJson = $input->hasOption('json');

        if (!file_exists($filePath)) {
            if ($isJson) {
                $output->writeln(json_encode(['error' => 'file_not_found', 'message' => 'Archive file not found'], JSON_THROW_ON_ERROR));
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
                $output->writeln(json_encode(['error' => 'invalid_archive', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $output->errorln(sprintf('Invalid archive: %s', $e->getMessage()));
            }

            return ExitCode::Error->value;
        }

        $macKey = null;
        if ($mode === 'tamper-evident' || $mode === 'full') {
            $macKey = $this->chainMacKey;
            if ($macKey === null) {
                if ($isJson) {
                    $output->writeln(json_encode([
                        'error' => 'mac_key_required',
                        'message' => 'Tamper-evident verification requires the chain MAC key (PULSAR_MASTER_KEY must be set)',
                    ], JSON_THROW_ON_ERROR));
                } else {
                    $output->errorln('Tamper-evident verification requires the chain MAC key (PULSAR_MASTER_KEY must be set)');
                }

                return ExitCode::Error->value;
            }
        }

        $result = $this->verifier->verify(
            chainLinks: $this->mergeChainWithEvents($archive),
            chainMacKey: $macKey,
        );

        if ($mode === 'full' && $this->archiveMacKey !== null) {
            $result['archive_mac_verified'] = $this->verifier->verifyArchiveMac($archive, $this->archiveMacKey);
        }

        $result['verification_mode'] = $mode;

        if ($isJson) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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

        $output->writeln(sprintf('  Mode:          %s', $verificationMode));
        $output->writeln(sprintf('  Chain mode:    %s', $chainMode));
        $output->writeln(sprintf('  Anchor:        %s', $anchorType));
        $output->writeln(sprintf('  Links:         %d verified', $linksVerified));

        if ($linksPruned > 0) {
            $output->writeln(sprintf('  Pruned:        %d links (by retention)', $linksPruned));
        }

        $output->newLine();

        if ($result['chain_intact']) {
            $output->success('Chain integrity verified.');
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
            $output->writeln(sprintf('  MAC verified:  %s', $result['mac_verified'] ? 'Yes' : 'FAILED'));
        }

        if (isset($result['archive_mac_verified'])) {
            $output->writeln(sprintf('  Archive MAC:   %s', $result['archive_mac_verified'] ? 'Verified' : 'FAILED'));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mergeChainWithEvents(EvidenceArchive $archive): array
    {
        $eventMap = [];
        foreach ($archive->events as $event) {
            /** @var string $eventId */
            $eventId = $event['event_id'] ?? '';
            $eventMap[$eventId] = $event;
        }

        $merged = [];
        foreach ($archive->chainLinks as $link) {
            /** @var string $linkEventId */
            $linkEventId = $link['event_id'] ?? '';
            $event = $eventMap[$linkEventId] ?? [];
            $merged[] = [...$link, ...$event];
        }

        return $merged;
    }
}
