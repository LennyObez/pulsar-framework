<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\ImportExport;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\FunnelServiceInterface;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ExportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportRequest;
use Pulsar\ImportExport\ImportResult;

use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sodium_crypto_generichash;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;

/**
 * Analytics import/export provider.
 *
 * Exports/imports analytics sites, goal definitions, and funnel definitions.
 */
#[Internal(reason: 'Wired in AnalyticsExtension::postBoot()')]
final readonly class AnalyticsImportExportProvider implements ImportExportProviderInterface
{
    private const array SUPPORTED_ENTITY_TYPES = [
        'sites',
        'goals',
        'funnels',
    ];

    public function __construct(
        private SiteRepositoryInterface $siteRepository,
        private GoalServiceInterface $goalService,
        private FunnelServiceInterface $funnelService,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'analytics';
    }

    #[Override]
    public function label(): string
    {
        return 'Analytics';
    }

    #[Override]
    public function supportedFormats(): array
    {
        return ['json'];
    }

    #[Override]
    public function export(ExportRequest $request): ExportResult
    {
        $entityTypes = $request->entityTypes !== []
            ? array_values(array_filter(
                $request->entityTypes,
                fn(string $t) => in_array($t, self::SUPPORTED_ENTITY_TYPES, true),
            ))
            : self::SUPPORTED_ENTITY_TYPES;

        $data = [];

        foreach ($entityTypes as $type) {
            $data[$type] = match ($type) {
                'sites' => $this->exportSites(),
                'goals' => $this->exportGoals(),
                'funnels' => $this->exportFunnels(),
                default => [],
            };
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $hash = bin2hex(sodium_crypto_generichash($json, '', SODIUM_CRYPTO_GENERICHASH_BYTES));

        return new ExportResult(
            providerName: 'analytics',
            data: $data,
            format: $request->format,
            evidenceHash: $hash,
            entityTypes: $entityTypes,
        );
    }

    #[Override]
    public function import(ImportRequest $request): ImportResult
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->content, true, 512, JSON_THROW_ON_ERROR);

        $created = [];
        $skipped = [];
        $warnings = [];
        $errors = [];

        foreach (self::SUPPORTED_ENTITY_TYPES as $type) {
            if (!array_key_exists($type, $payload) || !is_array($payload[$type])) {
                continue;
            }

            /** @var list<array<string, mixed>> $entityData */
            $entityData = $payload[$type];

            $result = match ($type) {
                'sites' => $this->importSites($entityData, $request->dryRun),
                'goals' => $this->importGoals($entityData, $request->dryRun),
                default => $this->importFunnels($entityData, $request->dryRun),
            };

            if ($result['created'] > 0) {
                $created[$type] = $result['created'];
            }

            if ($result['skipped'] > 0) {
                $skipped[$type] = $result['skipped'];
            }

            /** @var list<string> $typeWarnings */
            $typeWarnings = $result['warnings'];
            $warnings = [...$warnings, ...$typeWarnings];

            /** @var list<string> $typeErrors */
            $typeErrors = $result['errors'];
            $errors = [...$errors, ...$typeErrors];
        }

        return new ImportResult(
            providerName: 'analytics',
            created: $created,
            updated: [],
            skipped: $skipped,
            warnings: $warnings,
            errors: $errors,
            dryRun: $request->dryRun,
        );
    }

    #[Override]
    public function schema(): array
    {
        return [
            'sites' => [
                'id' => 'string (UUIDv7)',
                'domain' => 'string',
                'name' => 'string',
                'tracking_id' => 'string',
                'timezone' => 'string (IANA)',
                'settings' => 'array<string, mixed>',
            ],
            'goals' => [
                'id' => 'string (UUIDv7)',
                'site_id' => 'string (UUIDv7)',
                'name' => 'string',
                'goal_type' => 'string (page_visit, custom_event)',
                'target_value' => 'string',
            ],
            'funnels' => [
                'id' => 'string (UUIDv7)',
                'site_id' => 'string (UUIDv7)',
                'name' => 'string',
                'steps' => 'array<{position: int, name: string, type: string, value: string}>',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportSites(): array
    {
        $sites = $this->siteRepository->findAll();

        return array_map(fn($site) => [
            'id' => $site->id,
            'domain' => $site->domain,
            'name' => $site->name,
            'tracking_id' => $site->trackingId,
            'timezone' => $site->timezone,
            'settings' => $site->settings,
            'created_at' => $site->createdAt->format(DateTimeImmutable::ATOM),
        ], $sites);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportGoals(): array
    {
        $sites = $this->siteRepository->findAll();
        $goals = [];

        foreach ($sites as $site) {
            foreach ($this->goalService->listForSite($site->id) as $goal) {
                $goals[] = [
                    'id' => $goal->id,
                    'site_id' => $goal->siteId,
                    'name' => $goal->name,
                    'goal_type' => $goal->goalType->value,
                    'target_value' => $goal->targetValue,
                    'created_at' => $goal->createdAt->format(DateTimeImmutable::ATOM),
                ];
            }
        }

        return $goals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportFunnels(): array
    {
        $sites = $this->siteRepository->findAll();
        $funnels = [];

        foreach ($sites as $site) {
            foreach ($this->funnelService->listForSite($site->id) as $funnel) {
                $steps = array_map(fn($step) => [
                    'position' => $step->position,
                    'name' => $step->name,
                    'type' => $step->type->value,
                    'value' => $step->value,
                ], $funnel->steps);

                $funnels[] = [
                    'id' => $funnel->id,
                    'site_id' => $funnel->siteId,
                    'name' => $funnel->name,
                    'steps' => $steps,
                    'created_at' => $funnel->createdAt->format(DateTimeImmutable::ATOM),
                ];
            }
        }

        return $funnels;
    }

    /**
     * @param list<array<string, mixed>> $sites
     * @return array{created: int, skipped: int, warnings: list<string>, errors: list<string>}
     */
    private function importSites(array $sites, bool $dryRun): array
    {
        $created = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($sites as $entry) {
            $domain = $entry['domain'] ?? null;

            if (!is_string($domain) || $domain === '') {
                $warnings[] = 'Skipping site with missing domain';
                $skipped++;

                continue;
            }

            $existing = $this->siteRepository->findByDomain($domain);

            if ($existing !== null) {
                $skipped++;

                continue;
            }

            if (!$dryRun) {
                $site = new \Pulsar\Extension\Analytics\Domain\Site(
                    id: is_string($entry['id'] ?? null) ? $entry['id'] : bin2hex(random_bytes(16)),
                    domain: $domain,
                    name: is_string($entry['name'] ?? null) ? $entry['name'] : $domain,
                    trackingId: is_string($entry['tracking_id'] ?? null) ? $entry['tracking_id'] : 'plsr_' . bin2hex(random_bytes(8)),
                    timezone: is_string($entry['timezone'] ?? null) ? $entry['timezone'] : 'UTC',
                    settings: self::asStringKeyedArray($entry['settings'] ?? null),
                );
                $this->siteRepository->save($site);
            }

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'warnings' => $warnings, 'errors' => []];
    }

    /**
     * @param list<array<string, mixed>> $goals
     * @return array{created: int, skipped: int, warnings: list<string>, errors: list<string>}
     */
    private function importGoals(array $goals, bool $dryRun): array
    {
        $created = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($goals as $entry) {
            /** @var mixed $siteId */
            $siteId = $entry['site_id'] ?? null;
            /** @var mixed $name */
            $name = $entry['name'] ?? null;

            if (!is_string($siteId) || !is_string($name)) {
                $warnings[] = 'Skipping goal with missing site_id or name';
                $skipped++;

                continue;
            }

            /** @var mixed $rawGoalType */
            $rawGoalType = $entry['goal_type'] ?? null;
            $goalTypeValue = is_string($rawGoalType) ? $rawGoalType : '';
            $goalType = GoalType::tryFrom($goalTypeValue);

            if ($goalType === null) {
                $warnings[] = "Skipping goal '{$name}' with invalid goal_type '{$goalTypeValue}'";
                $skipped++;

                continue;
            }

            if (!$dryRun) {
                /** @var mixed $rawTargetValue */
                $rawTargetValue = $entry['target_value'] ?? null;
                $targetValue = is_string($rawTargetValue) ? $rawTargetValue : '';
                $this->goalService->create($siteId, $name, $goalType, $targetValue);
            }

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'warnings' => $warnings, 'errors' => []];
    }

    /**
     * @param list<array<string, mixed>> $funnels
     * @return array{created: int, skipped: int, warnings: list<string>, errors: list<string>}
     */
    private function importFunnels(array $funnels, bool $dryRun): array
    {
        $created = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($funnels as $entry) {
            /** @var mixed $siteId */
            $siteId = $entry['site_id'] ?? null;
            /** @var mixed $name */
            $name = $entry['name'] ?? null;

            if (!is_string($siteId) || !is_string($name)) {
                $warnings[] = 'Skipping funnel with missing site_id or name';
                $skipped++;

                continue;
            }

            /** @var mixed $rawStepsValue */
            $rawStepsValue = $entry['steps'] ?? null;
            $rawSteps = is_array($rawStepsValue) ? $rawStepsValue : [];

            if ($rawSteps === []) {
                $warnings[] = "Skipping funnel '{$name}' with no steps";
                $skipped++;

                continue;
            }

            if (!$dryRun) {
                $steps = [];
                $position = 1;

                foreach ($rawSteps as $stepData) {
                    if (!is_array($stepData)) {
                        continue;
                    }

                    $type = \Pulsar\Extension\Analytics\Domain\FunnelStepType::tryFrom(
                        is_string($stepData['type'] ?? null) ? $stepData['type'] : '',
                    );

                    if ($type === null) {
                        continue;
                    }

                    $steps[] = new \Pulsar\Extension\Analytics\Domain\FunnelStep(
                        position: is_int($stepData['position'] ?? null) ? $stepData['position'] : $position,
                        name: is_string($stepData['name'] ?? null) ? $stepData['name'] : "Step {$position}",
                        type: $type,
                        value: is_string($stepData['value'] ?? null) ? $stepData['value'] : '',
                    );
                    $position++;
                }

                if ($steps !== []) {
                    $this->funnelService->create($siteId, $name, $steps);
                }
            }

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'warnings' => $warnings, 'errors' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function asStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $result[$k] = $v;
            }
        }

        return $result;
    }
}
