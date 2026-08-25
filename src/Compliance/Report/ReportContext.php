<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Where and when the report was produced.
 *
 * A compliance artefact with no provenance is not an artefact. The SAPI is not
 * decoration either: the report is produced from a CLI boot, and extensions,
 * OPcache state and wiring outcomes can differ from the FPM process that serves
 * traffic. Printing it stops a CLI-shaped claim being handed over as a
 * statement about the web deployment.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ReportContext
{
    /**
     * @param non-empty-string $applicationName
     * @param non-empty-string $environment     The resolved APP_ENV, not the requested one
     * @param non-empty-string $phpVersion
     * @param non-empty-string $sapi
     * @param non-empty-string $frameworkSource Where the enabled framework list was read from
     */
    public function __construct(
        public string $applicationName,
        public string $environment,
        public DateTimeImmutable $generatedAt,
        public string $phpVersion,
        public string $sapi,
        public string $frameworkSource = 'config/compliance.php enabled_frameworks',
    ) {}

    /**
     * ISO-8601 with offset, the spelling every other Pulsar report uses.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public function generatedAtIso(): string
    {
        /** @var non-empty-string $formatted */
        $formatted = $this->generatedAt->format('Y-m-d\TH:i:sP');

        return $formatted;
    }
}
