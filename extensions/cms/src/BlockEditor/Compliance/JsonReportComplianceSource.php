<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Compliance;

use JsonException;
use Override;
use Pulsar\Api\Internal;

use function file_get_contents;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Reads observed statuses from the JSON artefact `pulsar compliance:report`
 * writes.
 *
 * ```
 * php bin/pulsar compliance:report --format=json > var/compliance/report.json
 * ```
 *
 * Generating that file is a deployment step, exactly like a build artefact, and
 * that is deliberate. The alternative — assessing on demand when a page renders
 * — would run a database query, execute health checks and recompute an HMAC per
 * stored audit record on every request from the public internet. The badge
 * therefore shows the timestamp of the run it read, so a page that has drifted
 * away from its evidence says so on its face rather than silently continuing to
 * assert a result from months ago.
 *
 * Every failure path yields "not assessed" rather than an exception: a missing
 * file, unreadable JSON or a schema version this class does not know. A page
 * request is the wrong place to raise a compliance error, and the safe direction
 * for a badge is always to claim less.
 */
#[Internal]
final class JsonReportComplianceSource implements ObservedComplianceSourceInterface
{
    /** The report schema this class understands; see JsonReportRenderer::SCHEMA_VERSION. */
    private const int SUPPORTED_SCHEMA_VERSION = 1;

    /** @var array<string, ObservedFrameworkStatus>|null Parsed once per instance. */
    private ?array $statuses = null;

    public function __construct(
        private readonly string $reportPath,
    ) {}

    #[Override]
    public function statusFor(string $frameworkKey): ?ObservedFrameworkStatus
    {
        $this->statuses ??= $this->parse();

        return $this->statuses[$frameworkKey] ?? null;
    }

    /**
     * @return array<string, ObservedFrameworkStatus>
     */
    private function parse(): array
    {
        if (!is_file($this->reportPath) || !is_readable($this->reportPath)) {
            return [];
        }

        $raw = file_get_contents($this->reportPath);

        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        // A file that is not this report, or is a shape this class was not
        // written against, is refused outright. Reading a moved field out of a
        // newer document and publishing the result would be worse than showing
        // nothing.
        if (($decoded['report'] ?? null) !== 'pulsar.compliance') {
            return [];
        }

        if (($decoded['schema_version'] ?? null) !== self::SUPPORTED_SCHEMA_VERSION) {
            return [];
        }

        $generatedAt = $decoded['generated_at'] ?? null;
        $controls = $decoded['controls'] ?? null;

        if (!is_string($generatedAt) || $generatedAt === '' || !is_array($controls)) {
            return [];
        }

        /** @var mixed $application */
        $application = $decoded['application'] ?? null;
        $environment = '';

        if (is_array($application)) {
            /** @var mixed $candidate */
            $candidate = $application['environment'] ?? null;

            if (is_string($candidate)) {
                $environment = $candidate;
            }
        }

        return $this->tally($controls, $generatedAt, $environment);
    }

    /**
     * @param array<array-key, mixed> $controls
     * @param non-empty-string        $generatedAt
     *
     * @return array<string, ObservedFrameworkStatus>
     */
    private function tally(array $controls, string $generatedAt, string $environment): array
    {
        /**
         * @var array<string, array{
         *     satisfied: int<0, max>,
         *     partial: int<0, max>,
         *     gaps: int<0, max>,
         *     checklist: int<0, max>,
         * }> $counts
         */
        $counts = [];

        /** @var mixed $control */
        foreach ($controls as $control) {
            if (!is_array($control)) {
                continue;
            }

            $framework = $control['framework'] ?? null;
            $outcome = $control['outcome'] ?? null;

            if (!is_string($framework) || $framework === '' || !is_string($outcome)) {
                continue;
            }

            $counts[$framework] ??= ['satisfied' => 0, 'partial' => 0, 'gaps' => 0, 'checklist' => 0];

            match ($outcome) {
                'satisfied' => $counts[$framework]['satisfied']++,
                'partial' => $counts[$framework]['partial']++,
                'unsatisfied' => $counts[$framework]['gaps']++,
                'operator_responsibility' => $counts[$framework]['checklist']++,
                // not_applicable rests on an operator assertion rather than on
                // anything observed, so it is excluded from both figures.
                default => null,
            };
        }

        $statuses = [];

        foreach ($counts as $framework => $tally) {
            $canonical = FrameworkLabels::canonical($framework);
            $label = $canonical === null ? null : FrameworkLabels::for($canonical);

            if ($canonical === null || $label === null) {
                continue;
            }

            $assessed = $tally['satisfied'] + $tally['partial'] + $tally['gaps'];

            $statuses[$framework] = new ObservedFrameworkStatus(
                frameworkKey: $canonical,
                label: $label,
                assessed: $assessed,
                satisfied: $tally['satisfied'],
                partial: $tally['partial'],
                gaps: $tally['gaps'],
                operatorChecklist: $tally['checklist'],
                generatedAt: $generatedAt,
                environment: $environment,
            );
        }

        return $statuses;
    }
}
