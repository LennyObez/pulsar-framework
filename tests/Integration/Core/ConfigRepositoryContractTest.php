<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\CloudConfig;
use Pulsar\Config\ApiConfig;
use Pulsar\Config\AppConfig;
use Pulsar\Config\BusinessProfileConfig;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\DeployConfig;
use Pulsar\Config\EventConfig;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Config\I18nConfig;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\MailConfig;
use Pulsar\Config\NotificationConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RoutingConfig;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Config\TenancyConfig;
use Pulsar\View\ViewConfig;

use function array_diff;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_values;
use function basename;
use function dirname;
use function glob;
use function implode;
use function in_array;
use function sort;

use const DIRECTORY_SEPARATOR;

/**
 * M0 wiring-contract gate — config-loading contract (F1).
 *
 * The single source of truth for "which config is loaded" must be the
 * {@see \Pulsar\Config\ConfigRepository}: a real boot builds every shipped
 * config's DTO into it, and consumers read it back typed. A config file that
 * ships, is documented, but whose DTO no production path ever builds is INERT —
 * the operator writes settings that silently do nothing. Half the wiring-audit's
 * inert-config criticals are exactly this.
 *
 * This gate asserts, against the framework's OWN shipped `config/`:
 *  1. every shipped config is explicitly classified — either it is consumed via
 *     the repository, or it is a documented known-gap; an unclassified config
 *     fails, so a newly-shipped config cannot silently be inert;
 *  2. every repository-consumed config builds its DTO in a real `load()`, and no
 *     UNEXPECTED DTO appears — so when a known-gap config is repatriated to the
 *     repository, its DTO surfaces here and forces its promotion out of the
 *     ledger (the ledger is self-cleaning, not a place debt can hide forever).
 *
 * The known-gaps ledger is the explicit, shrinking M0-F1 worklist. It reaches
 * empty when every consumed config flows through the repository; no GA tag before
 * then. See docs/audit/wiring-audit-2026-07/REMEDIATION-TASKS.md (F1).
 */
#[CoversClass(ConfigManager::class)]
final class ConfigRepositoryContractTest extends TestCase
{
    /**
     * Config basename => the DTO class a real boot must build into the
     * repository. This is the converged set: read back via
     * `repository()->get(Dto::class)`, single source of truth.
     *
     * @var array<string, class-string>
     */
    private const array CONSUMED_VIA_REPOSITORY = [
        'app' => AppConfig::class,
        'observability' => ObservabilityConfig::class,
        'security' => SecurityConfig::class,
        'i18n' => I18nConfig::class,
        'event' => EventConfig::class,
        'database' => DatabaseConfig::class,
        'tenancy' => TenancyConfig::class,
        'features' => FeatureFlagConfig::class,
        'scheduler' => SchedulerConfig::class,
        'resilience' => ResilienceConfig::class,
        'queue' => QueueConfig::class,
        'routing' => RoutingConfig::class,
        'storage' => StorageConfig::class,
        'supervisor' => SupervisorConfig::class,
        'integrity' => IntegrityConfig::class,
        'deploy' => DeployConfig::class,
        'runtime' => RuntimeConfig::class,
        'cache' => CacheConfig::class,
        'mail' => MailConfig::class,
        'notification' => NotificationConfig::class,
        'api' => ApiConfig::class,
        'view' => ViewConfig::class,
        'business' => BusinessProfileConfig::class,
        // Optional; no default config/cloud.php ships, so its DTO is only in the
        // repository when a project adds the file. Listed so it is never flagged
        // as an unexpected DTO.
        'cloud' => CloudConfig::class,
    ];

    /**
     * Known gaps: shipped configs NOT (yet) consumed via the repository, each
     * with the reason. This is the explicit M0-F1 worklist; it must shrink to
     * only the deliberate separate-path entries. Reconfirmed vs HEAD 2026-07-23.
     *
     * @var array<string, string>
     */
    private const array KNOWN_GAPS = [
        // Direct-read core configs — consumed, but a wiring reads the file itself
        // instead of the repository. Repatriate into the repository (M0-F1).
        'anti-spam' => 'direct-read: AntiSpamWiring builds 9 DTOs from the file — pending repatriation',
        'data_protection' => 'direct-read: SecurityWiring::buildDataProtectionConfig — pending repatriation',
        'documentation' => 'direct-read: DocumentationWiring — pending repatriation',
        'domains' => 'direct-read: SecurityWiring::buildDomainConfig — pending repatriation',
        'edge' => 'direct-read: EdgeWiring — pending repatriation',
        'introspection' => 'direct-read: IntrospectionWiring (needs EnvironmentMode) — pending repatriation',
        'profiler' => 'direct-read: ProfilerWiring — pending repatriation',
        // Extension-consumed — the owning extension reads the file directly.
        'admin' => 'extension-consumed: pulsar/admin reads it directly',
        'opentelemetry' => 'extension-consumed: pulsar/opentelemetry reads it directly',
        // INERT — shipped and documented, but NO production consumer. Wire a
        // consumer (build DTO + act on it) or remove the shipped file.
        'broadcasting' => 'INERT: BroadcastWiring declares configFile but never reads it',
        'compliance' => 'INERT: no production consumer (wiring-audit Tier-1 critical)',
        'dev' => 'INERT: no production consumer',
        'live' => 'INERT: no production consumer',
        'marketplace' => 'INERT: no production consumer',
        'openapi' => 'INERT: only a docblock references the file',
        'repl' => 'INERT: only comment/error-string references',
        'supply-chain' => 'INERT: only a docblock references the file',
        // Deliberate separate boot paths (not ConfigManager::load()).
        'studio' => 'separate path: loaded by Kernel::studioPreboot',
        'extensions' => 'not a config DTO: extension trust map, read by ExtensionDiscovery',
    ];

    #[Test]
    public function everyShippedConfigIsExplicitlyClassified(): void
    {
        $shipped = $this->shippedConfigBasenames();
        $classified = [...array_keys(self::CONSUMED_VIA_REPOSITORY), ...array_keys(self::KNOWN_GAPS)];

        // 1. No config is in BOTH lists.
        $both = array_values(array_intersect(
            array_keys(self::CONSUMED_VIA_REPOSITORY),
            array_keys(self::KNOWN_GAPS),
        ));
        sort($both);
        self::assertSame([], $both, 'Config classified as both consumed and a known-gap: ' . implode(', ', $both));

        // 2. Every shipped config is classified (consumed or a documented gap).
        $unclassified = array_diff($shipped, $classified);
        sort($unclassified);
        self::assertSame(
            [],
            $unclassified,
            'Shipped config(s) with no classification — add to CONSUMED_VIA_REPOSITORY or KNOWN_GAPS so a new '
            . 'config cannot silently ship inert: ' . implode(', ', $unclassified),
        );

        // 3. No stale entry: every classified basename (except the optional,
        //    not-shipped-by-default 'cloud') actually ships.
        $stale = array_diff($classified, [...$shipped, 'cloud']);
        sort($stale);
        self::assertSame([], $stale, 'Classified config no longer shipped — remove the stale entry: ' . implode(', ', $stale));
    }

    #[Test]
    public function consumedConfigsBuildTheirDtoInARealBoot(): void
    {
        $repository = $this->bootRealConfig();
        $loaded = array_map(static fn(object $o): string => $o::class, $repository->all());

        // Every shipped repository-consumed config builds its DTO.
        foreach (self::CONSUMED_VIA_REPOSITORY as $name => $dtoClass) {
            if (!$this->isShipped($name)) {
                continue; // e.g. cloud: no default file
            }
            self::assertContains(
                $dtoClass,
                $loaded,
                "config/{$name}.php ships but its DTO {$dtoClass} was not built into the repository by a real boot — inert.",
            );
        }

        // No UNEXPECTED DTO: everything in the repository is an expected consumed
        // DTO. When a known-gap config is repatriated, its DTO appears here and
        // this fails until it is promoted into CONSUMED_VIA_REPOSITORY — the
        // ledger cannot silently retain a config that is already converged.
        $unexpected = array_diff($loaded, array_values(self::CONSUMED_VIA_REPOSITORY));
        sort($unexpected);
        self::assertSame(
            [],
            $unexpected,
            'The repository holds DTO(s) not in CONSUMED_VIA_REPOSITORY. A known-gap config was likely repatriated — '
            . 'move it from KNOWN_GAPS to CONSUMED_VIA_REPOSITORY: ' . implode(', ', $unexpected),
        );
    }

    /**
     * @return list<string>
     */
    private function shippedConfigBasenames(): array
    {
        $configDir = $this->repoRoot() . DIRECTORY_SEPARATOR . 'config';
        $files = glob($configDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $names = array_map(static fn(string $f): string => basename($f, '.php'), $files);
        sort($names);

        return $names;
    }

    private function isShipped(string $name): bool
    {
        return in_array($name, $this->shippedConfigBasenames(), true);
    }

    private function bootRealConfig(): ConfigRepository
    {
        $configManager = new ConfigManager(configPath: $this->repoRoot() . DIRECTORY_SEPARATOR . 'config');
        $configManager->load();

        return $configManager->repository();
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
