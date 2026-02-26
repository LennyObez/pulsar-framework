<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Extension\Cms\Tools\SiteDefinition;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\ImportExport\ImportRequest;

use function array_key_exists;
use function is_array;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;

use const JSON_ERROR_NONE;
use const JSON_THROW_ON_ERROR;

/**
 * Facade service coordinating export/import operations across
 * the bundle generator, import parser, site definition parser,
 * and the central ImportExportRegistry for extension delegation.
 */
#[Internal(reason: 'Import/export internals; use ImportExportServiceInterface')]
/**
 * @psalm-api Bound to ImportExportServiceInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
final readonly class ImportExportService implements ImportExportServiceInterface
{
    /** @var list<string> Extension section keys that delegate to ImportExportRegistry providers */
    private const array EXTENSION_SECTIONS = ['forum', 'booking', 'analytics'];

    /** @var list<string> CMS-native section keys handled by ImportParser */
    private const array CMS_SECTIONS = ['content', 'taxonomies', 'menus', 'settings', 'media'];

    public function __construct(
        private ExportBundleGenerator $exportGenerator,
        private ImportParser $importParser,
        private SiteDefinitionParser $siteDefinitionParser,
        private ?ImportExportRegistry $registry = null,
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

    public function importUnifiedFile(string $jsonContent, bool $dryRun = true): ImportResult
    {
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return new ImportResult(
                created: [],
                updated: [],
                skipped: [],
                warnings: [],
                errors: ['Invalid JSON: ' . json_last_error_msg()],
                dryRun: $dryRun,
            );
        }

        // Full site definition: has version + site keys
        if (array_key_exists('site', $data) && array_key_exists('version', $data)) {
            return $this->importSiteDefinition($jsonContent, $dryRun);
        }

        // Provider-based bundle format: has "providers" key
        if (array_key_exists('providers', $data) && is_array($data['providers'])) {
            /** @var array<string, mixed> $providers */
            $providers = $data['providers'];
            return $this->importProviderBundle($providers, $dryRun);
        }

        $result = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: $dryRun,
        );

        // CMS-native sections: delegate to the bundle importer
        $hasCmsSections = false;

        foreach (self::CMS_SECTIONS as $section) {
            if (array_key_exists($section, $data)) {
                $hasCmsSections = true;

                break;
            }
        }

        if ($hasCmsSections) {
            $cmsResult = $this->importParser->importBundle($jsonContent, $dryRun);
            $result = $result->merge($cmsResult);
        }

        // Extension sections: delegate to ImportExportRegistry providers
        foreach (self::EXTENSION_SECTIONS as $section) {
            if (!array_key_exists($section, $data) || !is_array($data[$section])) {
                continue;
            }

            /** @var array<string, mixed> $sectionData */
            $sectionData = $data[$section];
            $extResult = $this->delegateToProvider($section, $sectionData, $dryRun);
            $result = $result->merge($extResult);
        }

        if ($result->created === [] && $result->updated === [] && $result->skipped === [] && $result->errors === []) {
            $result = $result->merge(new ImportResult(
                created: [],
                updated: [],
                skipped: [],
                warnings: ['No recognized import sections found in file'],
                errors: [],
                dryRun: $dryRun,
            ));
        }

        return $result;
    }

    /**
     * Import a multi-provider bundle where keys are provider names.
     *
     * @param array<string, mixed> $providers
     */
    private function importProviderBundle(array $providers, bool $dryRun): ImportResult
    {
        $result = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: $dryRun,
        );

        foreach ($providers as $providerName => $providerData) {
            if (!is_array($providerData)) {
                continue;
            }

            /** @var array<string, mixed> $providerData */
            $extResult = $this->delegateToProvider((string) $providerName, $providerData, $dryRun);
            $result = $result->merge($extResult);
        }

        return $result;
    }

    /**
     * Delegate a section to an ImportExportRegistry provider.
     *
     * @param array<string, mixed> $sectionData
     */
    private function delegateToProvider(string $providerName, array $sectionData, bool $dryRun): ImportResult
    {
        if ($this->registry === null) {
            return new ImportResult(
                created: [],
                updated: [],
                skipped: [],
                warnings: ["No import registry available to handle '$providerName' section"],
                errors: [],
                dryRun: $dryRun,
            );
        }

        $provider = $this->registry->getProvider($providerName);

        if ($provider === null) {
            return new ImportResult(
                created: [],
                updated: [],
                skipped: [],
                warnings: ["No import provider registered for '$providerName'"],
                errors: [],
                dryRun: $dryRun,
            );
        }

        $request = new ImportRequest(
            content: json_encode($sectionData, JSON_THROW_ON_ERROR),
            dryRun: $dryRun,
        );

        $providerResult = $provider->import($request);

        return new ImportResult(
            created: $providerResult->created,
            updated: $providerResult->updated,
            skipped: $providerResult->skipped,
            warnings: $providerResult->warnings,
            errors: $providerResult->errors,
            dryRun: $providerResult->dryRun,
        );
    }
}
