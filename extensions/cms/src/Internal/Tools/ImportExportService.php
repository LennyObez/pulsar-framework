<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

/**
 * Facade service coordinating export/import operations across
 * the bundle generator, import parser, and site definition parser.
 */
#[Internal(reason: 'Import/export internals — use ImportExportServiceInterface')]
final readonly class ImportExportService implements ImportExportServiceInterface
{
    public function __construct(
        private ExportBundleGenerator $exportGenerator,
        private ImportParser $importParser,
        private SiteDefinitionParser $siteDefinitionParser,
    ) {}

    public function exportBundle(ExportOptions $options): ExportBundle
    {
        return $this->exportGenerator->exportBundle($options);
    }

    public function importBundle(string $jsonContent, bool $dryRun = true): ImportResult
    {
        return $this->importParser->importBundle($jsonContent, $dryRun);
    }

    public function importSiteDefinition(string $jsonContent, bool $dryRun = true): ImportResult
    {
        $definition = SiteDefinition::fromJson($jsonContent);

        return $this->siteDefinitionParser->importSiteDefinition($definition, $dryRun);
    }
}
